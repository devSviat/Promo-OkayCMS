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

    public function __construct(EntityFactory $entityFactory)
    {
        $this->entityFactory = $entityFactory;
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

        $found = $campaigns->find(['id' => $promoIds, 'admin_list' => 1]);

        $candidates = [];
        foreach ($found as $c) {
            if (empty($c->id)) {
                continue;
            }
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
            /** @var PromoScopeEntity $scope */
            $scope = $this->entityFactory->get(PromoScopeEntity::class);
            $rows = $scope->find(['promo_id' => $campaignId]);
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
        $brandId     = (int) ($product->brand_id ?? 0);
        $categoryIds = $this->categoryIdsForProduct($productId, $product);
        $productValueIds = $this->featureValueIdsForProduct($productId);

        /** @var PromoScopeEntity $scope */
        $scope = $this->entityFactory->get(PromoScopeEntity::class);

        $candidatePromoIds = $scope->findPromoIdsForProduct($productId, $brandId, $categoryIds);
        if ($candidatePromoIds === []) {
            return [];
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

        return array_values(array_unique($matchedPromoIds));
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

        /** @var CategoriesEntity $categories */
        $categories = $this->entityFactory->get(CategoriesEntity::class);
        foreach ($categories->getProductCategories([$productId]) as $row) {
            if (!empty($row->category_id)) {
                $ids[] = (int) $row->category_id;
            }
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
