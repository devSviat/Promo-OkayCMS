<?php

namespace Okay\Modules\Sviat\Promo\Services;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\DiscountsEntity;
use Okay\Entities\ProductsEntity;
use Okay\Entities\PurchasesEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;

/**
 * Auto-applies the best active Sviat.Promo campaign (percent/fixed) to NEW
 * purchases added through the admin order edit page.
 *
 * Two ChainExtender hooks:
 *  - BackendOrdersHelper::addPurchase  → remember the newly-inserted purchase_id
 *  - BackendOrdersHelper::executeCustomPost → walk remembered purchases, attach
 *    the best matching campaign discount, recompute price under currency cents.
 */
class AdminOrderPromoApplier implements ExtensionInterface
{
    /** @var EntityFactory */
    private $entityFactory;

    /** @var PromotionEligibility */
    private $eligibility;

    /** @var int */
    private $currencyPrecision;

    /**
     * @var array<int, array{
     *   purchase_id: int,
     *   product_id: int,
     *   variant_id: int,
     *   amount: int,
     *   undiscounted_price: float
     * }>
     */
    private $newPurchases = [];

    public function __construct(EntityFactory $entityFactory, PromotionEligibility $eligibility)
    {
        $this->entityFactory = $entityFactory;
        $this->eligibility = $eligibility;

        /** @var CurrenciesEntity $currenciesEntity */
        $currenciesEntity = $entityFactory->get(CurrenciesEntity::class);
        $mainCurrency = $currenciesEntity->getMainCurrency();
        $this->currencyPrecision = max(0, (int) ($mainCurrency->cents ?? 2));
    }

    /**
     * Hook on BackendOrdersHelper::addPurchase.
     * Signature mirrors the original: ($purchaseId, $purchase).
     *
     * @param int|null $purchaseId
     * @param object   $purchase
     */
    public function rememberAddedPurchase($purchaseId, $purchase)
    {
        if (!$purchaseId || !is_object($purchase)) {
            return $purchaseId;
        }
        $pid = (int) $purchaseId;
        if ($pid < 1) {
            return $purchaseId;
        }
        $this->newPurchases[$pid] = [
            'purchase_id'        => $pid,
            'product_id'         => (int) ($purchase->product_id ?? 0),
            'variant_id'         => (int) ($purchase->variant_id ?? 0),
            'amount'             => max(1, (int) ($purchase->amount ?? 1)),
            'undiscounted_price' => (float) ($purchase->undiscounted_price ?? 0),
        ];
        return $purchaseId;
    }

    /**
     * Hook on BackendOrdersHelper::executeCustomPost.
     * Signature: ($returnValue, $order). $returnValue is null because the original
     * method's ExtenderFacade::execute call passes null.
     *
     * @param mixed  $return
     * @param object $order
     */
    public function applyPromosToNewPurchases($return, $order)
    {
        if ($this->newPurchases === []) {
            return $return;
        }

        try {
            foreach ($this->newPurchases as $info) {
                $this->applyPromoForPurchase($info);
            }
        } finally {
            $this->newPurchases = [];
        }

        return $return;
    }

    private function applyPromoForPurchase(array $info): void
    {
        $productId = $info['product_id'];
        $purchaseId = $info['purchase_id'];
        if ($productId < 1 || $purchaseId < 1) {
            return;
        }

        /** @var ProductsEntity $productsEntity */
        $productsEntity = $this->entityFactory->get(ProductsEntity::class);
        $product = $productsEntity->get($productId);
        if ($product === null || empty($product->id)) {
            return;
        }

        $promoIds = $this->eligibility->promoIdsForProduct($product);
        if ($promoIds === []) {
            return;
        }

        $campaigns = $this->eligibility->getActiveCampaigns(
            $promoIds,
            [PromoCampaignEntity::TYPE_PERCENT, PromoCampaignEntity::TYPE_FIXED]
        );
        if ($campaigns === []) {
            return;
        }
        $campaign = $campaigns[0]; // sorted by position ASC, id ASC

        $type = strtolower(trim((string) ($campaign->promo_type ?? '')));
        $undiscounted = (float) $info['undiscounted_price'];
        if ($undiscounted <= 0) {
            return;
        }

        $discountValue = null;
        $discountType  = null;
        $newPrice      = null;

        if ($type === PromoCampaignEntity::TYPE_PERCENT) {
            $pct = (float) ($campaign->discount_percent ?? 0);
            if ($pct <= 0 || $pct > 100) {
                return;
            }
            $discountType  = 'percent';
            $discountValue = $pct;
            $newPrice      = round($undiscounted * (100 - $pct) / 100, $this->currencyPrecision);
        } elseif ($type === PromoCampaignEntity::TYPE_FIXED) {
            $fixed = (float) ($campaign->discount_fixed ?? 0);
            if ($fixed <= 0 || $undiscounted <= $fixed) {
                return;
            }
            $discountType  = 'absolute';
            $discountValue = $fixed;
            $newPrice      = round($undiscounted - $fixed, $this->currencyPrecision);
        } else {
            return;
        }

        /** @var DiscountsEntity $discountsEntity */
        $discountsEntity = $this->entityFactory->get(DiscountsEntity::class);
        $discountsEntity->add([
            'entity'             => 'purchase',
            'entity_id'          => $purchaseId,
            'type'               => $discountType,
            'value'              => $discountValue,
            'from_last_discount' => 1,
            'position'           => 0,
            'name'               => (string) ($campaign->name ?? 'Акція'),
            'description'        => 'Знижка за акцією «' . (string) ($campaign->name ?? '') . '».',
        ]);

        /** @var PurchasesEntity $purchasesEntity */
        $purchasesEntity = $this->entityFactory->get(PurchasesEntity::class);
        $purchasesEntity->update($purchaseId, ['price' => $newPrice]);
    }
}
