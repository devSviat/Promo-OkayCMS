<?php

namespace Okay\Modules\Sviat\Promo\Extenders;

use Okay\Core\Cart;
use Okay\Core\Classes\Discount;
use Okay\Core\Classes\Purchase;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Entities\ProductsEntity;
use Okay\Entities\VariantsEntity;
use Okay\Helpers\ProductsHelper;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoRewardLineEntity;
use Okay\Modules\Sviat\Promo\Services\CartDiscountPipeline;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;

/**
 * Точки розширення кошика та доставки для кампаній Sviat.Promo.
 */
class PromoCartHooks implements ExtensionInterface
{
    /** @var ProductsHelper */
    private $productsHelper;

    /** @var ProductsEntity */
    private $productsEntity;

    /** @var VariantsEntity */
    private $variantsEntity;

    /** @var EntityFactory */
    private $entityFactory;

    /** @var Cart */
    private $cart;

    /** @var PromoRewardLineEntity */
    private $rewardLines;

    /** @var CartDiscountPipeline */
    private $discountPipeline;

    /** @var PromotionEligibility */
    private $eligibility;

    /** @var FrontTranslations */
    private $frontTranslations;

    public function __construct(
        EntityFactory $entityFactory,
        ProductsHelper $productsHelper,
        Cart $cart,
        CartDiscountPipeline $discountPipeline,
        PromotionEligibility $eligibility,
        FrontTranslations $frontTranslations
    ) {
        $this->productsHelper = $productsHelper;
        $this->entityFactory = $entityFactory;
        $this->cart = $cart;
        $this->discountPipeline = $discountPipeline;
        $this->eligibility = $eligibility;
        $this->frontTranslations = $frontTranslations;

        $this->productsEntity = $entityFactory->get(ProductsEntity::class);
        $this->variantsEntity = $entityFactory->get(VariantsEntity::class);
        $this->rewardLines = $entityFactory->get(PromoRewardLineEntity::class);
    }

    public function applySviatPromosToPurchases($cart)
    {
        $cart = $this->resolveChainedCart($cart);
        $this->discountPipeline->applyAfterPurchaseDiscounts($cart);

        return $cart;
    }

    /**
     * Додає відсоткову/фіксовану знижку як {@see Discount} до позиції кошика
     * з підписом `sviat_promo`.
     */
    public function attachSviatPromoPurchaseDiscounts(Cart $cart)
    {
        if ($cart->isEmpty) {
            return $cart;
        }

        $this->eligibility->resetCache();

        /** @var PromoCampaignEntity $campaignsEntity */
        $campaignsEntity = $this->entityFactory->get(PromoCampaignEntity::class);
        $promos = $campaignsEntity->find([
            'cart_active' => 1,
            'cart_promos' => 1,
        ]);
        if (empty($promos)) {
            return $cart;
        }

        foreach ($cart->purchases as $purchase) {
            if ($this->eligibility->lineIsBonusGift($purchase)) {
                continue;
            }

            if (!isset($purchase->meta) || !is_object($purchase->meta)) {
                $purchase->meta = new \stdClass();
            }
            unset($purchase->meta->sviat_promo_min_order_notice);

            $promo = $this->eligibility->pickBestDiscountCampaignForPurchase($purchase, $cart, $promos);
            if ($promo === null) {
                $pendingPromo = $this->pickBestMinOrderPendingCampaignForPurchase($purchase, $cart, $promos);
                if ($pendingPromo !== null) {
                    $minOrder = (float) ($pendingPromo->min_order_amount ?? 0);
                    $currentOrder = $this->eligibility->cartSubtotalAfterOwnDiscount($pendingPromo, $cart);
                    $purchase->meta->sviat_promo_min_order_notice = (object) [
                        'promo_name' => (string) ($pendingPromo->name ?? ''),
                        'min_order_amount' => $minOrder,
                        'current_order_amount' => $currentOrder,
                        'missing_amount' => max(0.0, $minOrder - $currentOrder),
                    ];
                }
                continue;
            }

            $type = strtolower(trim((string) ($promo->promo_type ?? '')));

            $d = new Discount();
            $d->sign = 'sviat_promo';
            $d->name = 'discount_sviat_promo_name';
            $d->description = 'discount_sviat_promo_description';
            $d->langParts['promo_name'] = (string) $promo->name;

            if ($type === PromoCampaignEntity::TYPE_PERCENT) {
                $pct = (float) ($promo->discount_percent ?? 0);
                if ($pct <= 0 || $pct > 100) {
                    continue;
                }

                $d->type = 'percent';
                $d->value = $pct;
            } elseif ($type === PromoCampaignEntity::TYPE_FIXED) {
                $fixed = (float) ($promo->discount_fixed ?? 0);
                if ($fixed <= 0) {
                    continue;
                }

                $price = (float) ($purchase->undiscounted_price ?? 0);
                if ($price < $fixed) {
                    continue;
                }

                $d->type = 'absolute';
                $d->value = $fixed;
            } else {
                continue;
            }

            $purchase->availableDiscounts['sviat_promo'] = $d;
        }

        return $cart;
    }

    /**
     * Найпріоритетніша відсоткова/фіксована кампанія для позиції, яка підходить за скопом/датою,
     * але не виконує мінімальну суму замовлення.
     */
    private function pickBestMinOrderPendingCampaignForPurchase(Purchase $purchase, Cart $cart, array $promos): ?object
    {
        $candidates = [];
        foreach ($promos as $promo) {
            if (empty($promo->id)) {
                continue;
            }

            $type = strtolower(trim((string) ($promo->promo_type ?? '')));
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

            if (!$this->eligibility->campaignDatesOk($promo)) {
                continue;
            }
            if (!$this->eligibility->purchaseMatchesCampaign($purchase, (int) $promo->id)) {
                continue;
            }
            if ((float) ($promo->min_order_amount ?? 0) <= 0) {
                continue;
            }
            if ($this->eligibility->minOrderSatisfiedAfterOwnDiscount($promo, $cart)) {
                continue;
            }

            $candidates[] = $promo;
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function ($a, $b) {
            $pa = (int) ($a->position ?? 0);
            $pb = (int) ($b->position ?? 0);
            if ($pa !== $pb) {
                return $pa - $pb;
            }

            return (int) ($a->id ?? 0) - (int) ($b->id ?? 0);
        });

        return $candidates[0];
    }

    /**
     * Пріоритетний набір «лише акція», якщо адмін ще не додав $sviat_promo у налаштуваннях знижок.
     *
     * @param array|mixed $sets
     * @return array|mixed
     */
    public function prependSviatPromoPurchaseSet($sets)
    {
        if (!is_array($sets)) {
            $sets = [];
        }
        foreach ($sets as $row) {
            if (is_object($row) && isset($row->set) && strpos((string) $row->set, 'sviat_promo') !== false) {
                return $sets;
            }
        }

        array_unshift($sets, (object) [
            'set' => '$sviat_promo',
            'partial' => false,
        ]);

        return $sets;
    }

    public function addPromoGiftPurchases($cart)
    {
        $cart = $this->resolveChainedCart($cart);

        $newPurchases = [];
        $campaignCache = [];
        $giftProductCache = [];
        $giftVariantCache = [];
        $giftCampaigns = null;
        $rewardRowsByPromo = [];
        $cartChanged = false;

        if (!isset($_SESSION['shopping_sviat_promo_gift']) || !is_array($_SESSION['shopping_sviat_promo_gift'])) {
            $_SESSION['shopping_sviat_promo_gift'] = [];
        }

        foreach ($cart->purchases as $key => $purchase) {
            $key = (string) $key;
            if (!isset($purchase->variant->gift_product_id) || !$purchase->variant->gift_product_id) {
                $variantId = (int) ($purchase->variant->id ?? 0);
                if (
                    $variantId > 0
                    && empty($_SESSION['shopping_sviat_promo_gift'][$variantId])
                    && ($autoGift = $this->pickAutoGiftSelectionForPurchase($purchase, $cart, $giftCampaigns, $rewardRowsByPromo))
                ) {
                    $_SESSION['shopping_sviat_promo_gift'][$variantId] = $autoGift;
                }

                if (isset($_SESSION['shopping_sviat_promo_gift'][$purchase->variant->id])) {
                    $purchaseGift = [];
                    $rewardRows = [];
                    $sess = $_SESSION['shopping_sviat_promo_gift'][$purchase->variant->id];
                    if (isset($sess['gift_id'], $sess['promo_id'])) {
                        $rewardRows = $this->rewardLines->find([
                            'gift_id' => $sess['gift_id'],
                            'promo_id' => $sess['promo_id'],
                            'visible' => 1,
                        ]);
                    }
                    foreach ($rewardRows as $key2 => $row) {
                        $promoId = (int) $row->promo_id;
                        if (!isset($campaignCache[$promoId])) {
                            $campaignCache[$promoId] = $this->entityFactory->get(PromoCampaignEntity::class)->get($promoId);
                        }
                        $campaign = $campaignCache[$promoId];
                        if (empty($campaign->id) || $campaign->promo_type !== PromoCampaignEntity::TYPE_GIFT) {
                            continue;
                        }
                        if (!$this->eligibility->campaignVisibleOnStorefront($campaign)) {
                            continue;
                        }
                        if (!$this->eligibility->campaignDatesOk($campaign)) {
                            continue;
                        }
                        $giftId = (int) $row->gift_id;
                        if (!isset($giftProductCache[$giftId])) {
                            $product = $this->productsEntity->findOne(['id' => $giftId]);
                            $giftProductCache[$giftId] = $this->productsHelper->attachImages([$product->id => $product])[$product->id];
                        }
                        $product = $giftProductCache[$giftId];

                        if (!isset($giftVariantCache[$giftId])) {
                            $giftVariantCache[$giftId] = $this->variantsEntity->findOne(['product_id' => $product->id]);
                        }
                        $variant = clone $giftVariantCache[$giftId];
                        $variant->gift_price = $variant->price;
                        $variant->price = 0;
                        $variant->gift_product_id = $purchase->product->id;

                        $purchaseGift[$key2] = new Purchase();
                        $purchaseGift[$key2]->product = $product;
                        $purchaseGift[$key2]->product_id = $product->id;
                        $purchaseGift[$key2]->product_name = $product->name;
                        $purchaseGift[$key2]->variant = $variant;
                        $purchaseGift[$key2]->variant_id = $variant->id;
                        $purchaseGift[$key2]->variant_name = $variant->name;
                        $purchaseGift[$key2]->undiscounted_price = $variant->price;
                        $purchaseGift[$key2]->sku = $variant->sku;
                        $purchaseGift[$key2]->units = $variant->units;
                        $purchaseGift[$key2]->meta->promo_id = (int) $row->promo_id;

                        $purchaseGift[$key2]->amount = 1;
                        // Ініціалізуємо підсумки, щоб Cart/Purchase не читали неіснуючі поля.
                        $purchaseGift[$key2]->updateTotals();
                        $newPurchases[$key] = $purchase;

                        $newPurchases[$key2 . '-' . $key2 . 'gift'] = $purchaseGift[$key2];
                        $cartChanged = true;
                    }
                    if (empty($purchaseGift)) {
                        $newPurchases[$key] = $purchase;
                    }
                } else {
                    $newPurchases[$key] = $purchase;
                }
            }
        }
        if ($cartChanged) {
            $cart->purchases = $newPurchases;
        }

        uksort($cart->purchases, function ($a, $b) {
            return strlen($a) <=> strlen($b);
        });

        $giftPromoIds = [];
        foreach ($cart->purchases as $purchase) {
            if (isset($purchase->meta->promo_id) && (int) $purchase->meta->promo_id > 0) {
                $giftPromoIds[] = (int) $purchase->meta->promo_id;
            }
        }
        $giftPromoIds = array_values(array_unique($giftPromoIds));

        $promoScopeProductIds = [];
        foreach ($giftPromoIds as $promoId) {
            $promos = $this->productsHelper->getList(['in_campaign' => $promoId]);
            $promoScopeProductIds[$promoId] = array_flip(array_map('intval', array_keys($promos)));
        }

        $triggerAmountsByPromo = array_fill_keys($giftPromoIds, 0);
        foreach ($cart->purchases as $purchase) {
            if (!empty($purchase->variant->gift_product_id)) {
                continue;
            }
            $productId = (int) ($purchase->product_id ?? 0);
            foreach ($giftPromoIds as $promoId) {
                if (isset($promoScopeProductIds[$promoId][$productId])) {
                    $triggerAmountsByPromo[$promoId] += (int) $purchase->amount;
                }
            }
        }

        foreach ($cart->purchases as $key => $purchase) {
            if (isset($purchase->meta->promo_id) && (int) $purchase->meta->promo_id > 0) {
                $promoId = (int) $purchase->meta->promo_id;
                $amounts = (int) ($triggerAmountsByPromo[$promoId] ?? 0);
                if ($amounts > 0) {
                    if ((int) $purchase->amount !== $amounts) {
                        $purchase->amount = $amounts;
                        $cartChanged = true;
                    }
                } else {
                    unset($cart->purchases[$key]);
                    $cartChanged = true;
                }
            }
        }

        if ($this->addBundleFreePurchases($cart)) {
            $cartChanged = true;
        }

        if ($cartChanged) {
            $cart->updateTotals();
        }

        return $cart;
    }

    /**
     * Автовибір подарунка, якщо користувач не обрав його вручну.
     * Беремо першу доступну gift-кампанію за пріоритетом і перший подарунок у ній.
     */
    private function pickAutoGiftSelectionForPurchase(Purchase $purchase, Cart $cart, ?array &$giftCampaigns, array &$rewardRowsByPromo): ?array
    {
        if ($giftCampaigns === null) {
            /** @var PromoCampaignEntity $campaignsEntity */
            $campaignsEntity = $this->entityFactory->get(PromoCampaignEntity::class);
            $giftCampaigns = $campaignsEntity->find([
                'cart_active' => 1,
                'cart_promos' => 1,
                'promo_type' => PromoCampaignEntity::TYPE_GIFT,
            ]);

            if (!empty($giftCampaigns)) {
                usort($giftCampaigns, static function ($a, $b) {
                    $pa = (int) ($a->position ?? 0);
                    $pb = (int) ($b->position ?? 0);
                    if ($pa !== $pb) {
                        return $pa - $pb;
                    }
                    return (int) ($a->id ?? 0) - (int) ($b->id ?? 0);
                });
            }
        }

        if (empty($giftCampaigns)) {
            return null;
        }

        foreach ($giftCampaigns as $campaign) {
            if (empty($campaign->id)) {
                continue;
            }
            if (!$this->eligibility->campaignDatesOk($campaign)) {
                continue;
            }
            if (!$this->eligibility->purchaseMatchesCampaign($purchase, (int) $campaign->id)) {
                continue;
            }
            if (!$this->eligibility->campaignMatchesCart($cart, $campaign)) {
                continue;
            }

            $promoId = (int) $campaign->id;
            if (!isset($rewardRowsByPromo[$promoId])) {
                $rewardRows = $this->rewardLines->find([
                    'promo_id' => $promoId,
                    'visible' => 1,
                ]);
                if (!empty($rewardRows)) {
                    usort($rewardRows, static function ($a, $b) {
                        return (int) ($a->position ?? 0) - (int) ($b->position ?? 0);
                    });
                }
                $rewardRowsByPromo[$promoId] = $rewardRows;
            }

            if (empty($rewardRowsByPromo[$promoId])) {
                continue;
            }

            $giftId = (int) ($rewardRowsByPromo[$promoId][0]->gift_id ?? 0);
            if ($giftId < 1) {
                continue;
            }

            return [
                'gift_id' => $giftId,
                'promo_id' => $promoId,
            ];
        }

        return null;
    }

    /**
     * Для 1+1=3 автододає безкоштовні позиції у вигляді gift-рядків:
     * за кожні 2 куплені одиниці додаємо 1 безкоштовну.
     */
    private function addBundleFreePurchases(Cart $cart): bool
    {
        $cartChanged = false;
        $basePurchases = [];
        foreach ($cart->purchases as $key => $purchase) {
            if (!empty($purchase->meta->sviat_promo_bundle_free)) {
                $cartChanged = true;
                continue;
            }
            $basePurchases[$key] = $purchase;
        }
        if ($cartChanged) {
            $cart->purchases = $basePurchases;
        }

        /** @var PromoCampaignEntity $campaignsEntity */
        $campaignsEntity = $this->entityFactory->get(PromoCampaignEntity::class);
        $bundleCampaigns = $campaignsEntity->find([
            'cart_active' => 1,
            'cart_promos' => 1,
            'promo_type' => PromoCampaignEntity::TYPE_BUNDLE_3X2,
        ]);

        if (empty($bundleCampaigns)) {
            return false;
        }

        usort($bundleCampaigns, static function ($a, $b) {
            $pa = (int) ($a->position ?? 0);
            $pb = (int) ($b->position ?? 0);
            if ($pa !== $pb) {
                return $pa - $pb;
            }

            return (int) ($a->id ?? 0) - (int) ($b->id ?? 0);
        });

        $suffix = 0;

        foreach ($bundleCampaigns as $campaign) {
            if (empty($campaign->id) || !$this->eligibility->campaignMatchesCart($cart, $campaign)) {
                continue;
            }

            $eligibleRows = [];
            $totalPaidUnits = 0;

            foreach ($cart->purchases as $sourceKey => $purchase) {
                if (!empty($purchase->variant->gift_product_id)) {
                    continue;
                }
                if (!$this->eligibility->purchaseMatchesCampaign($purchase, (int) $campaign->id)) {
                    continue;
                }

                $amount = max(0, (int) ($purchase->amount ?? 0));
                if ($amount < 1) {
                    continue;
                }

                $lineTotal = 0.0;
                if (isset($purchase->meta) && is_object($purchase->meta) && isset($purchase->meta->total_price)) {
                    $lineTotal = (float) $purchase->meta->total_price;
                } elseif (isset($purchase->price)) {
                    $lineTotal = (float) $purchase->price * $amount;
                }
                if ($lineTotal <= 0) {
                    continue;
                }

                $eligibleRows[] = (object) [
                    'key' => (string) $sourceKey,
                    'purchase' => $purchase,
                    'amount' => $amount,
                    'unit_price' => $lineTotal / $amount,
                ];
                $totalPaidUnits += $amount;
            }

            $freeUnits = (int) floor($totalPaidUnits / 2);
            if ($freeUnits < 1 || $eligibleRows === []) {
                continue;
            }

            usort($eligibleRows, static function ($a, $b) {
                return $a->unit_price <=> $b->unit_price;
            });

            foreach ($eligibleRows as $row) {
                if ($freeUnits < 1) {
                    break;
                }

                $unitsHere = min($freeUnits, (int) $row->amount);
                if ($unitsHere < 1) {
                    continue;
                }

                $giftPurchase = $this->buildBundleGiftPurchase($row->purchase, $campaign, $unitsHere, (float) $row->unit_price);
                $giftKey = $row->key . '-bundle-' . (int) $campaign->id . '-' . $suffix;
                $cart->purchases[$giftKey] = $giftPurchase;

                $suffix++;
                $freeUnits -= $unitsHere;
                $cartChanged = true;
            }
        }

        return $cartChanged;
    }

    private function buildBundleGiftPurchase(Purchase $source, object $campaign, int $amount, float $giftPrice): Purchase
    {
        $gift = new Purchase();
        $gift->product = $source->product;
        $gift->product_id = (int) ($source->product_id ?? 0);
        $gift->product_name = (string) ($source->product_name ?? $source->product->name ?? '');
        $gift->variant = clone $source->variant;
        $gift->variant_id = (int) ($source->variant_id ?? 0);
        $gift->variant_name = (string) ($source->variant_name ?? $source->variant->name ?? '');
        $gift->sku = (string) ($source->sku ?? '');
        $gift->units = (string) ($source->units ?? '');
        $gift->amount = max(1, $amount);

        $gift->variant->gift_price = $giftPrice;
        $gift->variant->price = 0;
        $gift->variant->gift_product_id = (int) ($source->product_id ?? 0);

        if (!isset($gift->meta) || !is_object($gift->meta)) {
            $gift->meta = new \stdClass();
        }
        $gift->meta->sviat_promo_bundle_free = 1;
        $gift->meta->sviat_promo_bundle_campaign_id = (int) ($campaign->id ?? 0);
        $gift->meta->sviat_promo_bundle_campaign_name = (string) ($campaign->name ?? '');

        $gift->undiscounted_price = 0.0;
        $gift->updateTotals();

        return $gift;
    }

    public function removePromoGiftPurchases($purchase, $variantId)
    {
        if (!empty($_SESSION['shopping_sviat_promo_gift'][$variantId])) {
            $count = 0;
            foreach ($this->cart->purchases as $key => $cartPurchase) {
                if ($cartPurchase->variant->id === $purchase->variant->id) {
                    $count++;
                    if ($count > 1 && !isset($cartPurchase->variant->gift_product_id)) {
                        unset($this->cart->purchases[$key]);
                    }
                }
            }
            unset($_SESSION['shopping_sviat_promo_gift'][$variantId]);
        }

        return $purchase;
    }

    public function getPromoGiftPurchases($result, $cart, $orderId)
    {
        foreach ($result->purchasesToDB as $key => $p) {
            if (isset($cart->purchases[$key]->variant->gift_product_id)) {
                $p->gift_product_id = $cart->purchases[$key]->variant->gift_product_id;
            }
        }
        unset($_SESSION['shopping_sviat_promo_gift']);

        return $result;
    }

    public function applyFreeShippingToCartDeliveries($deliveries, $cart, $paymentMethods)
    {
        if (empty($_SESSION[CartDiscountPipeline::SESSION_FREE_SHIPPING])) {
            return $deliveries;
        }

        foreach ($deliveries as $delivery) {
            $delivery->is_free_delivery = true;
            $delivery->delivery_price_text = $this->frontTranslations->getTranslation('cart_free');
            $delivery->total_price_with_delivery = $cart->total_price;
        }

        return $deliveries;
    }

    public function applyFreeShippingToOrderDelivery($deliveryPriceInfo, $delivery, $order)
    {
        if (empty($_SESSION[CartDiscountPipeline::SESSION_FREE_SHIPPING])) {
            return $deliveryPriceInfo;
        }

        $deliveryPriceInfo['delivery_price'] = 0;
        $deliveryPriceInfo['separate_delivery'] = $delivery->separate_payment;

        return $deliveryPriceInfo;
    }

    public function clearSviatPromoSession($cart)
    {
        $cart = $this->resolveChainedCart($cart);

        unset(
            $_SESSION['shopping_sviat_promo_gift'],
            $_SESSION[CartDiscountPipeline::SESSION_FREE_SHIPPING],
            $_SESSION[CartDiscountPipeline::SESSION_APPLIED]
        );

        return $cart;
    }

    /** У chain перший аргумент — вихід попереднього extender'а; якщо null — Cart з контейнера. */
    private function resolveChainedCart($cart): Cart
    {
        if ($cart instanceof Cart) {
            return $cart;
        }

        return $this->cart;
    }
}
