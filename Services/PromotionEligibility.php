<?php

namespace Okay\Modules\Sviat\Promo\Services;

use Okay\Core\Cart;
use Okay\Core\Classes\Purchase;
use Okay\Core\EntityFactory;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\FeaturesValuesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoScopeEntity;

/**
 * Перевіряє дати, мінімальну суму та відповідність товарів умовам кампанії.
 */
class PromotionEligibility
{
    private $entityFactory;

    /** @var array<int, array<int, object>> */
    private $scopeCache = [];

    /** @var array<int, object> */
    private $productCache = [];

    /** @var array<int, int[]> */
    private $featureValueCache = [];

    /** @var array<int, int> campaignId => exclude_no_image flag */
    private $excludeNoImageCache = [];

    /** @var array<int, int[]> productId => category_id[] з __products_categories */
    private $categoryRowsCache = [];

    /** @var array<int, int[]> productId => promo_id[], заповнюється префетчем */
    private $promoIdsCache = [];

    /** @var array<int, object|false> campaignId => рядок кампанії, false = відомо відсутня */
    private $campaignCache = [];

    /** @var array<int, int> productId => main_image_id зі списку */
    private $mainImageIdCache = [];

    /** @var array<int, object> товари поточного списку (можуть бути урізані) */
    private $listProducts = [];

    /** Таблиця скопів завелика — префетч вимкнено до кінця запиту. */
    private $scopePrefetchDisabled = false;

    /** Чи $scopeCache містить УСІ рядки скопів, а не лише запитані кампанії. */
    private $scopeCacheComplete = false;

    /** Захист від рекурсії: префетч сам смикає getList, який знову кличе префетч. */
    private $prefetching = false;

    /**
     * Понад стільки рядків таблицю скопів цілком не читаємо. Реально їх
     * одиниці — це запобіжник, а не робочий режим.
     */
    private const MAX_PREFETCHED_SCOPE_ROWS = 5000;

    public function __construct(EntityFactory $entityFactory)
    {
        $this->entityFactory = $entityFactory;
    }

    /**
     * Готує все потрібне для цілого списку товарів сталою кількістю запитів.
     *
     * Без цього кожна картка коштувала ~5 SQL (категорії, значення
     * характеристик, два запити по скопах, вибірка кампаній), тобто на
     * каталозі з 24 товарів — близько 120 запитів.
     *
     * @param array<int|string, mixed> $products масив товарів із getList()
     */
    public function prefetchForProducts(array $products): void
    {
        if ($this->prefetching) {
            return;
        }

        $productIds = [];
        foreach ($products as $product) {
            if (!is_object($product)) {
                continue;
            }
            $pid = (int) ($product->id ?? 0);
            if ($pid > 0 && !isset($this->promoIdsCache[$pid])) {
                $productIds[$pid] = $pid;
                $this->listProducts[$pid] = $product;
                // productCache свідомо не чіпаємо: він існує заради ПОВНОГО
                // рядка товару (productForPurchase), а сюди приходять об'єкти
                // з getList(), урізані за $excludedFields. Беремо лише те, що
                // нам справді треба.
                if (isset($product->main_image_id)) {
                    $this->mainImageIdCache[$pid] = (int) $product->main_image_id;
                }
            }
        }
        if ($productIds === []) {
            return;
        }

        $this->prefetching = true;
        try {
            $this->primeCategoryCache($productIds);
            $this->primeFeatureValueCache($productIds);
            if (!$this->primeScopeCache()) {
                return; // забагато скопів — лишаємось на потоварному шляху
            }

            foreach ($productIds as $pid) {
                $product = $this->listProducts[$pid] ?? null;
                $this->promoIdsCache[$pid] = $this->matchPromoIds(
                    $pid,
                    (int) ($product->brand_id ?? 0),
                    $this->categoryIdsForProduct($pid, $product),
                    $this->featureValueIdsForProduct($pid)
                );
            }
        } finally {
            $this->prefetching = false;
        }
    }

    /**
     * @param array<int, int> $productIds
     */
    private function primeCategoryCache(array $productIds): void
    {
        $missing = array_values(array_diff($productIds, array_keys($this->categoryRowsCache)));
        if ($missing === []) {
            return;
        }
        // Порожній фільтр у getProductCategories() віддає ВСЮ таблицю зв'язків.
        foreach ($missing as $pid) {
            $this->categoryRowsCache[$pid] = [];
        }

        /** @var CategoriesEntity $categories */
        $categories = $this->entityFactory->get(CategoriesEntity::class);
        foreach ($categories->getProductCategories($missing) as $row) {
            $pid = (int) ($row->product_id ?? 0);
            if ($pid > 0 && !empty($row->category_id)) {
                $this->categoryRowsCache[$pid][] = (int) $row->category_id;
            }
        }
    }

    /**
     * @param array<int, int> $productIds
     */
    private function primeFeatureValueCache(array $productIds): void
    {
        $missing = array_values(array_diff($productIds, array_keys($this->featureValueCache)));
        if ($missing === []) {
            return;
        }
        foreach ($missing as $pid) {
            $this->featureValueCache[$pid] = [];
        }

        /** @var FeaturesValuesEntity $fve */
        $fve = $this->entityFactory->get(FeaturesValuesEntity::class);
        foreach ($fve->getProductValuesIds($missing) as $row) {
            $pid = (int) ($row->product_id ?? 0);
            if ($pid > 0 && !empty($row->value_id)) {
                $this->featureValueCache[$pid][] = (int) $row->value_id;
            }
        }
    }

    /**
     * Читає таблицю скопів цілком. Коли вона в пам'яті, потоварний
     * пре-фільтр «кандидатів» (два SQL на товар) стає непотрібним: він
     * існував лише щоб не сканувати скопи.
     *
     * @return bool чи вдалося (false — таблиця завелика)
     */
    private function primeScopeCache(): bool
    {
        if ($this->scopeCacheComplete) {
            return true;
        }
        if ($this->scopePrefetchDisabled) {
            return false;
        }

        /** @var PromoScopeEntity $scope */
        $scope = $this->entityFactory->get(PromoScopeEntity::class);

        // Стелю перевіряємо зайвим рядком у вибірці, а не через count(): у
        // sviat__promo_object немає колонки id, а Entity::count() рахує саме
        // COUNT(DISTINCT alias.id).
        $rows = $scope->find(['limit' => self::MAX_PREFETCHED_SCOPE_ROWS + 1]);
        if (count($rows) > self::MAX_PREFETCHED_SCOPE_ROWS) {
            // Запам'ятовуємо рішення: інакше кожен блок товарів на головній
            // платив би за власну перевірку поверх потоварного шляху.
            $this->scopePrefetchDisabled = true;
            return false;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $promoId = (int) ($row->promo_id ?? 0);
            if ($promoId > 0) {
                $grouped[$promoId][] = $row;
            }
        }
        $this->scopeCache = $grouped;
        $this->scopeCacheComplete = true;

        if ($grouped !== []) {
            /** @var PromoCampaignEntity $campaigns */
            $campaigns = $this->entityFactory->get(PromoCampaignEntity::class);
            foreach ($campaigns->noLimit()->find(['id' => array_keys($grouped), 'admin_list' => 1]) as $campaign) {
                if (!empty($campaign->id)) {
                    $this->excludeNoImageCache[(int) $campaign->id] = (int) ($campaign->exclude_no_image ?? 0);
                    $this->campaignCache[(int) $campaign->id] = $campaign;
                }
            }
            // Скоп без кампанії — позначаємо як відомо відсутню, інакше
            // getActiveCampaigns() щоразу відкочувався б на запит.
            foreach (array_keys($grouped) as $promoId) {
                if (!array_key_exists($promoId, $this->campaignCache)) {
                    $this->campaignCache[$promoId] = false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<int, int>
     */
    private function matchPromoIds(int $productId, int $brandId, array $categoryIds, array $productValueIds): array
    {
        $matched = [];
        foreach (array_keys($this->scopeCache) as $promoId) {
            if ($this->productMatchesCampaignByData((int) $promoId, $productId, $brandId, $categoryIds, $productValueIds)) {
                $matched[] = (int) $promoId;
            }
        }

        return $matched;
    }

    /**
     * @param mixed $raw значення з БД / об'єкта
     */
    private function normalizePromoType($raw): string
    {
        return strtolower(trim((string) $raw));
    }

    public function resetCache(): void
    {
        $this->scopeCache        = [];
        $this->productCache      = [];
        $this->featureValueCache = [];
        $this->excludeNoImageCache = [];
        $this->categoryRowsCache = [];
        $this->promoIdsCache     = [];
        $this->campaignCache     = [];
        $this->mainImageIdCache  = [];
        $this->listProducts      = [];
        $this->scopeCacheComplete = false;
        $this->scopePrefetchDisabled = false;
    }

    /**
     * Повний рядок товару з БД для перевірки скопу
     * (у кошику об'єкт product інколи «урізаний»).
     */
    private function productForPurchase(Purchase $purchase): object
    {
        $pid = (int) $purchase->product_id;
        if ($pid < 1) {
            return $purchase->product;
        }
        if (!isset($this->productCache[$pid])) {
            /** @var ProductsEntity $products */
            $products = $this->entityFactory->get(ProductsEntity::class);
            $full = $products->get($pid);
            $this->productCache[$pid] = ($full !== null && !empty($full->id)) ? $full : $purchase->product;
        }

        return $this->productCache[$pid];
    }

    private function mainImageIdForProduct(int $productId): int
    {
        if ($productId < 1) {
            return 0;
        }
        if (isset($this->mainImageIdCache[$productId])) {
            return $this->mainImageIdCache[$productId];
        }
        $product = $this->productCache[$productId] ?? null;
        if ($product !== null) {
            return (int) ($product->main_image_id ?? 0);
        }
        /** @var ProductsEntity $products */
        $products = $this->entityFactory->get(ProductsEntity::class);
        $full = $products->get($productId);
        if ($full !== null && !empty($full->id)) {
            $this->productCache[$productId] = $full;
            return (int) ($full->main_image_id ?? 0);
        }
        return 0;
    }

    public function getCartSubtotal(Cart $cart): float
    {
        $sum = 0.0;
        foreach ($cart->purchases as $purchase) {
            if ($this->lineIsBonusGift($purchase)) {
                continue;
            }
            $totalPrice = 0.0;
            if (!empty($purchase->meta) && isset($purchase->meta->total_price)) {
                $totalPrice = (float) $purchase->meta->total_price;
            } elseif (isset($purchase->price) && isset($purchase->amount)) {
                // Запасний варіант: amount × price, якщо meta->total_price відсутня
                $totalPrice = (float) $purchase->price * (int) $purchase->amount;
            }
            $sum += $totalPrice;
        }

        return $sum;
    }

    public function lineIsBonusGift(Purchase $purchase): bool
    {
        return !empty($purchase->variant->gift_product_id);
    }

    /**
     * Розбирає дату/час закінчення акції. Тепер зберігається повний datetime («Y-m-d H:i:s»),
     * тому просто конвертуємо через strtotime без приведення до кінця дня.
     *
     * @param mixed $dateEnd значення з БД
     */
    private function endOfPromoDayTimestamp($dateEnd): ?int
    {
        $s = trim((string) $dateEnd);
        if ($s === '') {
            return null;
        }
        $t = strtotime($s);
        return $t !== false ? $t : null;
    }

    private function excludeNoImageForCampaign(int $campaignId): bool
    {
        if (!isset($this->excludeNoImageCache[$campaignId])) {
            /** @var PromoCampaignEntity $campaignsEntity */
            $campaignsEntity = $this->entityFactory->get(PromoCampaignEntity::class);
            $campaign = $campaignsEntity->findOne(['id' => $campaignId, 'admin_list' => 1]);
            $this->excludeNoImageCache[$campaignId] = (int) ($campaign->exclude_no_image ?? 0);
        }
        return $this->excludeNoImageCache[$campaignId] === 1;
    }

    /**
     * Чи кампанія показується на вітрині: лише visible=1 (без винятку для адміна).
     */
    public function campaignVisibleOnStorefront(object $campaign): bool
    {
        return (int) ($campaign->visible ?? 0) === 1;
    }

    public function campaignDatesOk(object $campaign): bool
    {
        if (empty($campaign->has_date_range)) {
            return true;
        }
        $now = time();
        if (!empty($campaign->date_start)) {
            $startTs = strtotime((string) $campaign->date_start);
            if ($startTs !== false && $startTs > $now) {
                return false;
            }
        }
        if (!empty($campaign->date_end)) {
            $endTs = $this->endOfPromoDayTimestamp($campaign->date_end);
            if ($endTs !== null && $endTs < $now) {
                return false;
            }
        }

        return true;
    }

    /**
     * Порівняння для usort: спочатку position ASC, потім id ASC.
     */
    private function compareCampaignsByPriority(object $a, object $b): int
    {
        $pa = (int) ($a->position ?? 0);
        $pb = (int) ($b->position ?? 0);
        if ($pa !== $pb) {
            return $pa - $pb;
        }

        return (int) $a->id - (int) $b->id;
    }

    /**
     * Відсоткова/фіксована кампанія для позиції: найвищий пріоритет серед підходящих.
     *
     * @param array<int, object> $promos зазвичай find(cart_active, cart_promos)
     */
    public function pickBestDiscountCampaignForPurchase(Purchase $purchase, Cart $cart, array $promos): ?object
    {
        $candidates = [];
        foreach ($promos as $promo) {
            if (empty($promo->id)) {
                continue;
            }

            $type = $this->normalizePromoType($promo->promo_type ?? '');
            if ($type === PromoCampaignEntity::TYPE_PERCENT) {
                $pct = (float) ($promo->discount_percent ?? 0);
                if ($pct <= 0 || $pct > 100) {
                    continue;
                }
            } elseif ($type === PromoCampaignEntity::TYPE_FIXED) {
                $fixed = (float) ($promo->discount_fixed ?? 0);
                if ($fixed <= 0) {
                    continue;
                }
            } else {
                continue;
            }

            $candidates[] = $promo;
        }
        if ($candidates === []) {
            return null;
        }

        usort($candidates, [$this, 'compareCampaignsByPriority']);

        foreach ($candidates as $promo) {
            if (!$this->campaignMatchesCart($cart, $promo)) {
                continue;
            }
            if (!$this->minOrderSatisfiedAfterOwnDiscount($promo, $cart)) {
                continue;
            }
            if (!$this->purchaseMatchesCampaign($purchase, (int) $promo->id)) {
                continue;
            }

            return $promo;
        }

        return null;
    }

    /**
     * Повертає всі активні (видимі, з валідною датою) кампанії зі списку promoIds,
     * відсортовані за пріоритетом (position ASC, id ASC).
     * Один запит замість N окремих get(); admin_list=1 вимикає SQL-фільтр visible, далі відсікаємо вручну.
     *
     * @param array<int, int> $promoIds
     * @param string[]|null $allowedTypes якщо задано — лише ці promo_type
     * @return array<int, object>
     */
    public function getActiveCampaigns(array $promoIds, ?array $allowedTypes = null): array
    {
        $promoIds = array_values(array_unique(array_map('intval', $promoIds)));
        if ($promoIds === []) {
            return [];
        }

        /** @var PromoCampaignEntity $campaigns */
        $campaigns = $this->entityFactory->get(PromoCampaignEntity::class);
        $normalizedAllowed = null;
        if ($allowedTypes !== null) {
            $normalizedAllowed = array_map(function ($t) {
                return $this->normalizePromoType((string) $t);
            }, $allowedTypes);
        }

        // Префетч уже поклав кампанії в пам'ять — інакше цей запит виконувався б
        // на кожну картку товару (плагін бейджа кличе pickBestActiveCampaign).
        // Кеш попозиційний і зберігає false для відомо відсутніх: інакше один
        // осиротілий рядок скопу (кампанію видалили повз CampaignRepository)
        // вимикав би кеш цілком і повертав запит на кожну картку.
        $cachedAll = [];
        foreach ($promoIds as $promoId) {
            if (!array_key_exists($promoId, $this->campaignCache)) {
                $cachedAll = null;
                break;
            }
            if ($this->campaignCache[$promoId] !== false) {
                $cachedAll[] = $this->campaignCache[$promoId];
            }
        }

        $found = $cachedAll ?? $campaigns->find(['id' => $promoIds, 'admin_list' => 1]);

        $candidates = [];
        foreach ($found as $c) {
            if (empty($c->id)) {
                continue;
            }
            $this->excludeNoImageCache[(int) $c->id] = (int) ($c->exclude_no_image ?? 0);
            if ((int) ($c->visible ?? 0) !== 1) {
                continue;
            }
            if (!$this->campaignDatesOk($c)) {
                continue;
            }
            $typeNorm = $this->normalizePromoType($c->promo_type ?? '');
            if ($normalizedAllowed !== null && !in_array($typeNorm, $normalizedAllowed, true)) {
                continue;
            }
            $candidates[] = $c;
        }

        if ($candidates !== []) {
            usort($candidates, [$this, 'compareCampaignsByPriority']);
        }

        return $candidates;
    }

    /**
     * З кількох promo_id обирає видиму кампанію з валідними датами та найвищим пріоритетом.
     *
     * @param array<int, int> $promoIds
     * @param string[]|null $allowedTypes якщо задано — лише ці promo_type
     */
    public function pickBestActiveCampaign(array $promoIds, ?array $allowedTypes = null): ?object
    {
        $candidates = $this->getActiveCampaigns($promoIds, $allowedTypes);

        return $candidates !== [] ? $candidates[0] : null;
    }

    public function minOrderSatisfied(object $campaign, Cart $cart): bool
    {
        $min = (float) ($campaign->min_order_amount ?? 0);
        if ($min <= 0) {
            return true;
        }

        return $this->getCartSubtotal($cart) >= $min;
    }

    /**
     * Для відсоткової/фіксованої кампанії перевіряємо поріг за сумою замовлення
     * після застосування саме цієї знижки.
     */
    public function minOrderSatisfiedAfterOwnDiscount(object $campaign, Cart $cart): bool
    {
        $min = (float) ($campaign->min_order_amount ?? 0);
        if ($min <= 0) {
            return true;
        }

        $subtotal = $this->getCartSubtotal($cart);
        if ($subtotal < $min) {
            return false;
        }

        $afterPromoSubtotal = $this->cartSubtotalAfterOwnDiscount($campaign, $cart);

        return $afterPromoSubtotal >= $min;
    }

    public function cartSubtotalAfterOwnDiscount(object $campaign, Cart $cart): float
    {
        $subtotal = $this->getCartSubtotal($cart);
        $discountAmount = $this->estimateCampaignDiscountForCart($campaign, $cart);

        return max(0.0, $subtotal - $discountAmount);
    }

    private function estimateCampaignDiscountForCart(object $campaign, Cart $cart): float
    {
        $campaignId = (int) ($campaign->id ?? 0);
        if ($campaignId < 1) {
            return 0.0;
        }

        $type = $this->normalizePromoType($campaign->promo_type ?? '');
        if ($type !== PromoCampaignEntity::TYPE_PERCENT && $type !== PromoCampaignEntity::TYPE_FIXED) {
            return 0.0;
        }

        $discountTotal = 0.0;
        foreach ($cart->purchases as $purchase) {
            if ($this->lineIsBonusGift($purchase)) {
                continue;
            }
            if (!$this->purchaseMatchesCampaign($purchase, $campaignId)) {
                continue;
            }

            $amount = max(1, (int) ($purchase->amount ?? 0));
            $lineTotal = 0.0;
            if (!empty($purchase->meta) && isset($purchase->meta->total_price)) {
                $lineTotal = (float) $purchase->meta->total_price;
            } elseif (isset($purchase->price) && isset($purchase->amount)) {
                $lineTotal = (float) $purchase->price * (int) $purchase->amount;
            }
            if ($lineTotal <= 0) {
                continue;
            }

            if ($type === PromoCampaignEntity::TYPE_PERCENT) {
                $pct = (float) ($campaign->discount_percent ?? 0);
                if ($pct <= 0 || $pct > 100) {
                    continue;
                }
                $discountTotal += $lineTotal * ($pct / 100);
                continue;
            }

            $fixed = (float) ($campaign->discount_fixed ?? 0);
            if ($fixed <= 0) {
                continue;
            }
            $unitPrice = $lineTotal / $amount;
            if ($unitPrice < $fixed) {
                continue;
            }
            $discountTotal += $fixed * $amount;
        }

        return $discountTotal;
    }

    /**
     * @return array<int, object>
     */
    public function scopeRowsForCampaign(int $campaignId): array
    {
        if (!isset($this->scopeCache[$campaignId])) {
            // Кеш повний — відсутність ключа означає «скопів немає», а не
            // «ще не завантажили».
            if ($this->scopeCacheComplete) {
                return [];
            }
            /** @var PromoScopeEntity $scope */
            $scope = $this->entityFactory->get(PromoScopeEntity::class);
            // noLimit(): дефолтні 100 рядків мовчки обрізали б скоп, а обрізані
            // рядки ВИКЛЮЧЕННЯ означали б знижку тим, кого мали виключити.
            $rows = $scope->noLimit()->find(['promo_id' => $campaignId]);
            $this->scopeCache[$campaignId] = is_array($rows) ? $rows : [];
        }

        return $this->scopeCache[$campaignId];
    }

    public function purchaseMatchesCampaign(Purchase $purchase, int $campaignId): bool
    {
        $rows = $this->scopeRowsForCampaign($campaignId);
        if ($rows === []) {
            return false;
        }

        $productId   = (int) $purchase->product_id;
        $productRow  = $this->productForPurchase($purchase);
        $brandId     = (int) ($productRow->brand_id ?? 0);
        $categoryIds = $this->categoryIdsForProduct($productId, $productRow);
        $productValueIds = $this->featureValueIdsForProduct($productId);

        return $this->productMatchesCampaignByData(
            $campaignId,
            $productId,
            $brandId,
            $categoryIds,
            $productValueIds
        );
    }

    /**
     * Ідентифікатори акцій, чия область дії перетинається з товаром (узгоджено з вибіркою на лендінгу акції).
     *
     * @return array<int, int>
     */
    public function promoIdsForProduct(object $product): array
    {
        $productId   = (int) $product->id;
        if (isset($this->promoIdsCache[$productId])) {
            return $this->promoIdsCache[$productId];
        }

        $brandId     = (int) ($product->brand_id ?? 0);
        $categoryIds = $this->categoryIdsForProduct($productId, $product);
        $productValueIds = $this->featureValueIdsForProduct($productId);

        // Скопи вже цілком у пам'яті — пре-фільтр кандидатів не потрібен.
        if ($this->scopeCacheComplete) {
            return $this->promoIdsCache[$productId]
                = $this->matchPromoIds($productId, $brandId, $categoryIds, $productValueIds);
        }

        /** @var PromoScopeEntity $scope */
        $scope = $this->entityFactory->get(PromoScopeEntity::class);

        $candidatePromoIds = $scope->findPromoIdsForProduct($productId, $brandId, $categoryIds);
        if ($candidatePromoIds === []) {
            return $this->promoIdsCache[$productId] = [];
        }

        $matchedPromoIds = [];
        foreach ($candidatePromoIds as $promoId) {
            $promoId = (int) $promoId;
            if ($promoId < 1) {
                continue;
            }
            if ($this->productMatchesCampaignByData($promoId, $productId, $brandId, $categoryIds, $productValueIds)) {
                $matchedPromoIds[] = $promoId;
            }
        }

        // Мемоїзуємо й тут: на сторінці товару префетчу немає, а результат
        // питають PromoProductDisplayService, GiftBadgePlugin і блок кампанії —
        // без цього кожен повторював обидва запити findPromoIdsForProduct().
        return $this->promoIdsCache[$productId] = array_values(array_unique($matchedPromoIds));
    }

    /**
     * Усі category_id товару з __products_categories + main_category_id
     * (як у фільтрі каталогу акції).
     *
     * @return array<int, int>
     */
    public function categoryIdsForProduct(int $productId, ?object $product = null): array
    {
        $ids = [];
        if ($product !== null && !empty($product->main_category_id)) {
            $ids[] = (int) $product->main_category_id;
        }

        // main_category_id домішуємо щоразу, а кешуємо лише рядки зв'язків:
        // так кеш не залежить від того, який об'єкт товару передали.
        if (!isset($this->categoryRowsCache[$productId])) {
            $this->primeCategoryCache([$productId => $productId]);
        }

        foreach ($this->categoryRowsCache[$productId] as $categoryId) {
            $ids[] = (int) $categoryId;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function featureValueIdsForProduct(int $productId): array
    {
        if (!isset($this->featureValueCache[$productId])) {
            /** @var FeaturesValuesEntity $fve */
            $fve  = $this->entityFactory->get(FeaturesValuesEntity::class);
            $rows = $fve->getProductValuesIds([$productId]);
            $ids  = [];
            foreach ($rows as $row) {
                $ids[] = (int) $row->value_id;
            }
            $this->featureValueCache[$productId] = $ids;
        }

        return $this->featureValueCache[$productId];
    }

    public function productMatchesCampaignByProductData(
        int $campaignId,
        int $productId,
        int $brandId,
        int $mainCategoryId
    ): bool {
        if ($campaignId < 1 || $productId < 1) {
            return false;
        }

        $categoryIds = $this->categoryIdsForProduct($productId, (object) ['main_category_id' => $mainCategoryId]);
        $productValueIds = $this->featureValueIdsForProduct($productId);

        return $this->productMatchesCampaignByData($campaignId, $productId, $brandId, $categoryIds, $productValueIds);
    }

    private function productMatchesCampaignByData(
        int $campaignId,
        int $productId,
        int $brandId,
        array $categoryIds,
        array $productValueIds
    ): bool {
        if ($this->excludeNoImageForCampaign($campaignId)) {
            $mainImageId = $this->mainImageIdForProduct($productId);
            if ($mainImageId < 1) {
                return false;
            }
        }

        $rows = $this->scopeRowsForCampaign($campaignId);
        if ($rows === []) {
            return false;
        }

        $inclusions = [];
        $exclusions = [];
        foreach ($rows as $row) {
            if (!empty($row->exclude)) {
                $exclusions[] = $row;
            } else {
                $inclusions[] = $row;
            }
        }

        if (!$this->matchesRowsByAndLogic($inclusions, $productId, $brandId, $categoryIds, $productValueIds)) {
            return false;
        }

        if (!empty($exclusions) && $this->matchesRowsByAndLogic($exclusions, $productId, $brandId, $categoryIds, $productValueIds)) {
            return false;
        }

        return true;
    }

    private function matchesRowsByAndLogic(
        array $rows,
        int $productId,
        int $brandId,
        array $categoryIds,
        array $productValueIds
    ): bool {
        if (empty($rows)) {
            return false;
        }

        $products = [];
        $brands = [];
        $categories = [];
        $featureGroups = [];

        foreach ($rows as $row) {
            $type = (string) ($row->type ?? '');
            $objectId = (int) ($row->object_id ?? 0);
            if ($objectId < 1) {
                continue;
            }

            if ($type === 'product') {
                $products[$objectId] = $objectId;
            } elseif ($type === 'brand') {
                $brands[$objectId] = $objectId;
            } elseif ($type === 'category') {
                $categories[$objectId] = $objectId;
            } elseif ($type === 'feature_value') {
                $featureId = (int) ($row->feature_id ?? 0);
                if ($featureId > 0) {
                    $featureGroups[$featureId][$objectId] = $objectId;
                }
            }
        }

        if (!empty($products) && !isset($products[$productId])) {
            return false;
        }
        if (!empty($brands) && ($brandId < 1 || !isset($brands[$brandId]))) {
            return false;
        }
        if (!empty($categories) && empty(array_intersect(array_values($categories), $categoryIds))) {
            return false;
        }

        if (!empty($featureGroups)) {
            foreach ($featureGroups as $valueIdsByFeature) {
                if (empty(array_intersect(array_values($valueIdsByFeature), $productValueIds))) {
                    return false;
                }
            }
        }

        // Рядки є, але жоден не придатний (object_id = 0, невідомий type,
        // feature_value без feature_id) — це не «умов немає, отже підходить
        // усе». Раніше такі кампанії відсікав SQL-пре-фільтр, який вимагав
        // збігу object_id; без цієї перевірки кампанія з порожнім скопом
        // застосувалася б до всього каталогу.
        if (empty($products) && empty($brands) && empty($categories) && empty($featureGroups)) {
            return false;
        }

        return true;
    }

    public function cartHasEligibleLine(Cart $cart, object $campaign): bool
    {
        foreach ($cart->purchases as $purchase) {
            if ($this->lineIsBonusGift($purchase)) {
                continue;
            }
            if ($this->purchaseMatchesCampaign($purchase, (int) $campaign->id)) {
                return true;
            }
        }

        return false;
    }

    public function campaignMatchesCart(Cart $cart, object $campaign): bool
    {
        if (!$this->campaignVisibleOnStorefront($campaign)) {
            return false;
        }
        if (!$this->campaignDatesOk($campaign)) {
            return false;
        }
        if (!$this->minOrderSatisfied($campaign, $cart)) {
            return false;
        }

        return $this->cartHasEligibleLine($cart, $campaign);
    }

    /**
     * @return Purchase[]
     */
    public function purchasesInCampaignScope(Cart $cart, int $campaignId): array
    {
        $out = [];
        foreach ($cart->purchases as $purchase) {
            if ($this->lineIsBonusGift($purchase)) {
                continue;
            }
            if ($this->purchaseMatchesCampaign($purchase, $campaignId)) {
                $out[] = $purchase;
            }
        }

        return $out;
    }
}
