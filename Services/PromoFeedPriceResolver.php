<?php

namespace Okay\Modules\Sviat\Promo\Services;

use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\OkayCMS\Feeds\Entities\FeedsEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoFeedLinkEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoScopeEntity;

/**
 * Завантажує наперед кампанії для фідів, їх скопи і прив'язки до фідів
 * перед циклом товарів в експорті.
 *
 * Схема використання:
 *   1. $resolver->setCurrentFeed('feeds', $feedId);   // викликає екстендер
 *   2. $resolver->preload();                           // завантаження один раз за запит
 *   3. $resolver->findBestCampaign(…);                // виклик для кожного товару
 */
class PromoFeedPriceResolver
{
    /** @var EntityFactory */
    private $entityFactory;

    /** @var QueryFactory */
    private $queryFactory;

    /** @var array<int, object>|null null = ще не завантажено */
    private $activeCampaigns = null;

    /**
     * @var array<int, array{
     *   inclusions: array{products: int[], brands: int[], categories: int[]},
     *   exclusions: array{products: int[], brands: int[], categories: int[]}
     * }>
     */
    private $scopesByCampaign = [];

    /** @var array<int, array<string, int[]>> promo_id => feed_type => [feed_id, ...] */
    private $feedLinksByCampaign = [];

    /** @var array<int, int> categoryId => parentId */
    private $categoryParents = [];

    /** @var array<int, array<int, true>> product_id => [value_id => true] */
    private $productFeatureValuesByProduct = [];

    /** @var PromotionEligibility */
    private $eligibility;

    /** @var string|null */
    private $currentFeedType = null;

    /** @var int|null */
    private $currentFeedId = null;

    /** @var float|null null — не пресет Feeds або не завантажено */
    private $feedPriceChangePercent = null;

    /** @var int */
    private $currencyPrecision;

    public function __construct(EntityFactory $entityFactory, QueryFactory $queryFactory, PromotionEligibility $eligibility)
    {
        $this->entityFactory = $entityFactory;
        $this->queryFactory  = $queryFactory;
        $this->eligibility   = $eligibility;
        /** @var CurrenciesEntity $currenciesEntity */
        $currenciesEntity = $entityFactory->get(CurrenciesEntity::class);
        $mainCurrency = $currenciesEntity->getMainCurrency();
        $this->currencyPrecision = max(0, (int) ($mainCurrency->cents ?? 2));
    }

    /**
     * Встановлює контекст поточного фіда перед preload() і findBestCampaign().
     * Викликати один раз для кожної генерації фіда.
     *
     * @param string $type  PromoFeedLinkEntity::TYPE_FEEDS | TYPE_GM
     * @param int    $feedId
     */
    public function setCurrentFeed(string $type, int $feedId): void
    {
        if ($type !== $this->currentFeedType || $feedId !== $this->currentFeedId) {
            $this->clearPreloadCache();
        }
        $this->currentFeedType = $type;
        $this->currentFeedId   = $feedId;

        if ($type === PromoFeedLinkEntity::TYPE_FEEDS && $feedId > 0) {
            $this->loadFeedPriceChangePercent((int) $feedId);
        } else {
            $this->feedPriceChangePercent = null;
        }
    }

    /**
     * Відсоток «Зміна ціни» з налаштувань фіда OkayCMS/Feeds (для узгодження з modifyItem + price_change).
     */
    public function getFeedPriceChangePercent(): float
    {
        return $this->feedPriceChangePercent !== null ? (float) $this->feedPriceChangePercent : 0.0;
    }

    private function loadFeedPriceChangePercent(int $feedId): void
    {
        $this->feedPriceChangePercent = 0.0;
        /** @var FeedsEntity $feeds */
        $feeds = $this->entityFactory->get(FeedsEntity::class);
        $feed  = $feeds->findOne(['id' => $feedId]);
        if ($feed === null || !is_array($feed->settings ?? null)) {
            return;
        }
        if (!empty($feed->settings['price_change'])) {
            $this->feedPriceChangePercent = (float) $feed->settings['price_change'];
        }
    }

    /**
     * Скидає кеш preload, коли змінюється фід (тип/id), щоб наступний preload()
     * не «залипав» на порожньому результаті від попереднього фіда в тому ж процесі PHP.
     */
    private function clearPreloadCache(): void
    {
        $this->activeCampaigns      = null;
        $this->scopesByCampaign     = [];
        $this->feedLinksByCampaign  = [];
        $this->categoryParents      = [];
        $this->productFeatureValuesByProduct = [];
        $this->feedPriceChangePercent = null;
    }

    /**
     * Завантажує всі кампанії з увімкненими фідами, скопи і прив'язки.
     * На один контекст фіда (після setCurrentFeed) виконується один раз; при зміні фіда — знову.
     */
    public function preload(): void
    {
        if ($this->activeCampaigns !== null) {
            return;
        }

        $this->activeCampaigns      = [];
        $this->scopesByCampaign     = [];
        $this->feedLinksByCampaign  = [];
        $this->categoryParents      = [];
        $this->productFeatureValuesByProduct = [];

        /** @var PromoCampaignEntity $campaignsEntity */
        $campaignsEntity = $this->entityFactory->get(PromoCampaignEntity::class);
        // Без admin_list: у сутності додається sp.visible = 1 — «вимкнені» кампанії не потрапляють у вибірку взагалі.
        $all = $campaignsEntity->find([]);

        foreach ($all as $c) {
            if ((int) ($c->visible ?? 0) !== 1) {
                continue;
            }
            if (!in_array($c->promo_type, [PromoCampaignEntity::TYPE_PERCENT, PromoCampaignEntity::TYPE_FIXED], true)) {
                continue;
            }
            if (!$this->eligibility->campaignDatesOk($c)) {
                continue;
            }
            if ((int) ($c->feed_enabled ?? 0) !== 1) {
                continue;
            }
            $this->activeCampaigns[(int) $c->id] = $c;
        }

        if (empty($this->activeCampaigns)) {
            return;
        }

        $promoIds = array_keys($this->activeCampaigns);

        // Завантажуємо скопи кампаній (для швидкої перевірки без SQL у циклі фіда)
        /** @var PromoScopeEntity $scopeEntity */
        $scopeEntity = $this->entityFactory->get(PromoScopeEntity::class);
        $scopes = $scopeEntity->find(['promo_id' => $promoIds]);

        $featureValueIds = [];
        foreach ($scopes as $row) {
            $promoId = (int) ($row->promo_id ?? 0);
            if ($promoId < 1) {
                continue;
            }

            if (!isset($this->scopesByCampaign[$promoId])) {
                $this->scopesByCampaign[$promoId] = [
                    'inclusions' => [
                        'has_rows' => false,
                        'products' => [],
                        'brands' => [],
                        'categories' => [],
                        'feature_groups' => [],
                    ],
                    'exclusions' => [
                        'has_rows' => false,
                        'products' => [],
                        'brands' => [],
                        'categories' => [],
                        'feature_groups' => [],
                    ],
                ];
            }

            $bucket = !empty($row->exclude) ? 'exclusions' : 'inclusions';
            $this->scopesByCampaign[$promoId][$bucket]['has_rows'] = true;

            $type = (string) ($row->type ?? '');
            $objectId = (int) ($row->object_id ?? 0);
            if ($objectId < 1) {
                continue;
            }

            if ($type === 'product') {
                $this->scopesByCampaign[$promoId][$bucket]['products'][$objectId] = true;
            } elseif ($type === 'brand') {
                $this->scopesByCampaign[$promoId][$bucket]['brands'][$objectId] = true;
            } elseif ($type === 'category') {
                $this->scopesByCampaign[$promoId][$bucket]['categories'][$objectId] = true;
            } elseif ($type === 'feature_value') {
                $featureId = (int) ($row->feature_id ?? 0);
                if ($featureId > 0) {
                    if (!isset($this->scopesByCampaign[$promoId][$bucket]['feature_groups'][$featureId])) {
                        $this->scopesByCampaign[$promoId][$bucket]['feature_groups'][$featureId] = [];
                    }
                    $this->scopesByCampaign[$promoId][$bucket]['feature_groups'][$featureId][$objectId] = true;
                    $featureValueIds[$objectId] = $objectId;
                }
            }
        }

        // Кешуємо відповідність product_id -> value_id лише для потрібних promo feature_value
        if (!empty($featureValueIds)) {
            $select = $this->queryFactory->newSelect();
            $select
                ->from('__products_features_values')
                ->cols(['product_id', 'value_id'])
                ->where('value_id IN (:sv_promo_fv_ids)')
                ->bindValue('sv_promo_fv_ids', array_values($featureValueIds));

            foreach ($select->results() as $row) {
                $productId = (int) ($row->product_id ?? 0);
                $valueId = (int) ($row->value_id ?? 0);
                if ($productId > 0 && $valueId > 0) {
                    $this->productFeatureValuesByProduct[$productId][$valueId] = true;
                }
            }
        }

        // Завантажуємо прив'язки до фідів
        /** @var PromoFeedLinkEntity $feedLinksEntity */
        $feedLinksEntity = $this->entityFactory->get(PromoFeedLinkEntity::class);
        $this->feedLinksByCampaign = $feedLinksEntity->getLinkedFeedsGrouped($promoIds);
        foreach ($this->feedLinksByCampaign as $pid => $byType) {
            foreach ($byType as $t => $ids) {
                $this->feedLinksByCampaign[$pid][$t] = array_values(array_map('intval', (array) $ids));
            }
        }

        // Відкидаємо кампанії без конкретних прив'язок (feed_enabled=1, але фіди не обрані)
        foreach (array_keys($this->activeCampaigns) as $pid) {
            if (empty($this->feedLinksByCampaign[$pid])) {
                unset($this->activeCampaigns[$pid]);
            }
        }

        if (empty($this->activeCampaigns)) {
            return;
        }

        // Карта батьківських категорій (без додаткових SQL під час ітерації фіда)
        $sql = $this->queryFactory->newSelect();
        $sql->from(CategoriesEntity::getTable())->cols(['id', 'parent_id']);
        foreach ($sql->results() as $row) {
            $this->categoryParents[(int) $row->id] = (int) $row->parent_id;
        }
    }

    public function isLoaded(): bool
    {
        return $this->activeCampaigns !== null;
    }

    /**
     * Шукає кампанію з найвищим пріоритетом, яка:
     *  – прив'язана до поточного фіда (type + id),
     *  – підходить товару за правилами скопу.
     */
    public function findBestCampaign(int $productId, int $brandId, int $mainCategoryId): ?object
    {
        if (empty($this->activeCampaigns)) {
            return null;
        }

        $eligible = $this->getCampaignsForCurrentFeed();
        if (empty($eligible)) {
            return null;
        }

        $candidates = [];

        foreach ($eligible as $campaign) {
            $cid   = (int) $campaign->id;
            if ($this->matchesCampaignScope($cid, $productId, $brandId, $mainCategoryId)) {
                $candidates[] = $campaign;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, static function (object $a, object $b): int {
            $pa = (int) ($a->position ?? 0);
            $pb = (int) ($b->position ?? 0);
            if ($pa !== $pb) {
                return $pa - $pb;
            }
            return (int) $a->id - (int) $b->id;
        });

        return $candidates[0];
    }

    /**
     * Рахує акційну ціну. Повертає null, якщо кампанія або ціна невалідні.
     */
    public function computePromoPrice(object $campaign, float $basePrice): ?float
    {
        if ($basePrice <= 0) {
            return null;
        }

        $type = strtolower(trim((string) ($campaign->promo_type ?? '')));

        if ($type === PromoCampaignEntity::TYPE_PERCENT) {
            $pct = (float) ($campaign->discount_percent ?? 0);
            if ($pct <= 0 || $pct > 100) {
                return null;
            }
            return round($basePrice * (100 - $pct) / 100, $this->currencyPrecision);
        }

        if ($type === PromoCampaignEntity::TYPE_FIXED) {
            $fixed = (float) ($campaign->discount_fixed ?? 0);
            if ($fixed <= 0 || $basePrice <= $fixed) {
                return null;
            }
            return round($basePrice - $fixed, $this->currencyPrecision);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Допоміжні методи
    // -------------------------------------------------------------------------

    /**
     * Форматує значення `g:sale_price_effective_date` для Google Merchant фідів.
     * Використовується в PromoFeedsExtender і PromoGoogleMerchantExtender.
     *
     * Інтервал у UTC за unix-часом з БД (як і strtotime), з повною датою/часом —
     * щоб кінець на кшталт date_end 2026-04-19 12:48:00 не розтягувався до кінця доби.
     */
    public function buildSalePriceDateRange(?string $dateStart, string $dateEnd): ?string
    {
        $endTs = strtotime(trim($dateEnd));
        if ($endTs === false) {
            return null;
        }

        if ($dateStart !== null && trim($dateStart) !== '') {
            $startTs = strtotime(trim($dateStart));
            if ($startTs === false) {
                return null;
            }
        } else {
            $startTs = time();
        }

        $startStr = gmdate('Y-m-d\TH:i:s\Z', $startTs);
        $endStr   = gmdate('Y-m-d\TH:i:s\Z', $endTs);

        return $startStr . '/' . $endStr;
    }

    /**
     * Повертає тільки кампанії, прив'язані до поточного фіда (type + id).
     *
     * @return array<int, object>
     */
    private function getCampaignsForCurrentFeed(): array
    {
        if ($this->currentFeedType === null || $this->currentFeedId === null) {
            return [];
        }

        $result = [];
        $currentId = (int) $this->currentFeedId;
        foreach ($this->activeCampaigns as $cid => $campaign) {
            $feedIds = $this->feedLinksByCampaign[$cid][$this->currentFeedType] ?? [];
            $feedIds = array_map('intval', (array) $feedIds);
            if (in_array($currentId, $feedIds, true)) {
                $result[$cid] = $campaign;
            }
        }
        return $result;
    }

    private function getCategoryAncestors(int $categoryId): array
    {
        if ($categoryId < 1) {
            return [];
        }

        $ids     = [];
        $current = $categoryId;
        $visited = [];
        while ($current > 0 && !isset($visited[$current])) {
            $ids[]             = $current;
            $visited[$current] = true;
            $current           = $this->categoryParents[$current] ?? 0;
        }
        return $ids;
    }

    private function matchesCampaignScope(int $campaignId, int $productId, int $brandId, int $mainCategoryId): bool
    {
        $scope = $this->scopesByCampaign[$campaignId] ?? null;
        if (empty($scope)) {
            return false;
        }

        $categoryAncestors = $this->getCategoryAncestors($mainCategoryId);
        $productFeatureValueIds = array_keys($this->productFeatureValuesByProduct[$productId] ?? []);

        if (!$this->matchesScopeBucket($scope['inclusions'], $productId, $brandId, $categoryAncestors, $productFeatureValueIds)) {
            return false;
        }

        if (!empty($scope['exclusions']['has_rows'])
            && $this->matchesScopeBucket($scope['exclusions'], $productId, $brandId, $categoryAncestors, $productFeatureValueIds)
        ) {
            return false;
        }

        return true;
    }

    private function matchesScopeBucket(
        array $bucket,
        int $productId,
        int $brandId,
        array $categoryAncestors,
        array $productFeatureValueIds
    ): bool {
        if (empty($bucket['has_rows'])) {
            return false;
        }

        if (!empty($bucket['products']) && empty($bucket['products'][$productId])) {
            return false;
        }

        if (!empty($bucket['brands']) && ($brandId < 1 || empty($bucket['brands'][$brandId]))) {
            return false;
        }

        if (!empty($bucket['categories'])) {
            $categoryMatch = false;
            foreach ($categoryAncestors as $categoryId) {
                if (!empty($bucket['categories'][$categoryId])) {
                    $categoryMatch = true;
                    break;
                }
            }
            if (!$categoryMatch) {
                return false;
            }
        }

        if (!empty($bucket['feature_groups'])) {
            foreach ($bucket['feature_groups'] as $valueIdsByFeature) {
                $featureMatched = false;
                foreach ($productFeatureValueIds as $valueId) {
                    if (!empty($valueIdsByFeature[$valueId])) {
                        $featureMatched = true;
                        break;
                    }
                }
                if (!$featureMatched) {
                    return false;
                }
            }
        }

        return true;
    }
}
