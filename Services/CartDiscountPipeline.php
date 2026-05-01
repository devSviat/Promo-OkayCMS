<?php

namespace Okay\Modules\Sviat\Promo\Services;

use Okay\Core\Cart;
use Okay\Core\Classes\Discount;
use Okay\Core\EntityFactory;
use Okay\Core\Classes\Purchase;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;

/**
 * Після знижок на варіанти застосовує правила кампаній (відсоток, фікс, 1+1=3, доставка).
 * Стан для відображення / доставки зберігається в сесії (див. SESSION_* константи), без полів на {@see Cart}.
 */
class CartDiscountPipeline
{
    public const SESSION_FREE_SHIPPING = 'sviat_promo_free_shipping';
    public const SESSION_APPLIED = 'sviat_promo_applied';

    private $entityFactory;

    /** @var PromotionEligibility */
    private $eligibility;

    /** @var int */
    private $currencyPrecision;

    public function __construct(EntityFactory $entityFactory, PromotionEligibility $eligibility)
    {
        $this->entityFactory = $entityFactory;
        $this->eligibility = $eligibility;
        $this->currencyPrecision = $this->resolveCurrencyPrecision();
    }

    public function applyAfterPurchaseDiscounts(Cart $cart): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_APPLIED] = [];
            unset($_SESSION[self::SESSION_FREE_SHIPPING]);
        }

        if ($cart->isEmpty) {
            return;
        }

        $this->eligibility->resetCache();

        /** @var PromoCampaignEntity $campaigns */
        $campaigns = $this->entityFactory->get(PromoCampaignEntity::class);
        $promos = $campaigns->find([
            'cart_active' => 1,
            'cart_promos' => 1,
        ]);

        if (empty($promos)) {
            return;
        }

        $this->resetBundleHints($cart);

        $this->normalizeAppliedPromoRounding($cart);

        $this->applyPercentAndFixedFallback($cart, $promos);
        $this->rebuildSviatPromoTotals($cart);

        foreach ($promos as $promo) {
            if (!$this->eligibility->campaignMatchesCart($cart, $promo)) {
                continue;
            }
            // TYPE_PERCENT і TYPE_FIXED обробляються через механізм Discount
            // (attachSviatPromoPurchaseDiscounts).
            // TYPE_GIFT обробляється в addPromoGiftPurchases.
            // Тут лишається обробка тільки BUNDLE і FREE_SHIPPING.
            switch ($promo->promo_type) {
                case PromoCampaignEntity::TYPE_BUNDLE_3X2:
                    $this->attachBundleHint($promo, $cart);
                    $this->appendApplied($promo);
                    break;
                case PromoCampaignEntity::TYPE_FREE_SHIPPING:
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        $_SESSION[self::SESSION_FREE_SHIPPING] = true;
                    }
                    $this->appendApplied($promo);
                    break;
                default:
                    break;
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION[self::SESSION_FREE_SHIPPING])) {
            unset($_SESSION[self::SESSION_FREE_SHIPPING]);
        }

        $cart->undiscounted_total_price = 0;
        foreach ($cart->purchases as $purchase) {
            $cart->undiscounted_total_price += $purchase->meta->total_price;
        }
    }

    /**
     * Резервне застосування відсоткових/фіксованих кампаній, якщо набори знижок
     * не додали `sviat_promo` до позиції кошика.
     *
     * @param array<int, object> $promos
     */
    private function applyPercentAndFixedFallback(Cart $cart, array $promos): void
    {
        $appliedPromoIds = [];

        foreach ($cart->purchases as $purchase) {
            if ($this->eligibility->lineIsBonusGift($purchase)) {
                continue;
            }

            if ($this->hasSviatPromoDiscountApplied($purchase)) {
                continue;
            }

            $promo = $this->eligibility->pickBestDiscountCampaignForPurchase($purchase, $cart, $promos);
            if ($promo === null) {
                continue;
            }

            $amount = max(1, (int) ($purchase->amount ?? 0));
            $lineBefore = (float) ($purchase->meta->total_price ?? 0);
            if ($lineBefore <= 0) {
                continue;
            }
            $unitPriceBefore = $lineBefore / $amount;
            $unitPriceAfter = $unitPriceBefore;

            $type = strtolower(trim((string) ($promo->promo_type ?? '')));
            if ($type === PromoCampaignEntity::TYPE_PERCENT) {
                $pct = (float) ($promo->discount_percent ?? 0);
                if ($pct <= 0 || $pct > 100) {
                    continue;
                }
                $unitPriceAfter = $this->roundPrice($unitPriceBefore * (100 - $pct) / 100);
            } elseif ($type === PromoCampaignEntity::TYPE_FIXED) {
                $fixed = (float) ($promo->discount_fixed ?? 0);
                if ($fixed <= 0 || $unitPriceBefore < $fixed) {
                    continue;
                }
                $unitPriceAfter = $this->roundPrice($unitPriceBefore - $fixed);
            } else {
                continue;
            }

            $lineAfter = $this->roundPrice($unitPriceAfter * $amount);
            if ($lineAfter >= $lineBefore) {
                continue;
            }

            $discount = $this->buildSviatPromoDiscount($promo, $unitPriceBefore, $unitPriceAfter);
            $purchase->discounts[] = $discount;
            $this->appendToCartPurchaseDiscountTotals($cart, $discount, $amount);

            $purchase->price = $unitPriceAfter;
            $purchase->meta->total_price = $lineAfter;

            $promoId = (int) ($promo->id ?? 0);
            if ($promoId > 0 && !isset($appliedPromoIds[$promoId])) {
                $this->appendApplied($promo);
                $appliedPromoIds[$promoId] = true;
            }
        }
    }

    private function hasSviatPromoDiscountApplied(Purchase $purchase): bool
    {
        if (!empty($purchase->discounts)) {
            foreach ($purchase->discounts as $discount) {
                if ($discount instanceof Discount && $discount->sign === 'sviat_promo') {
                    return true;
                }
                if (is_object($discount) && isset($discount->sign) && (string) $discount->sign === 'sviat_promo') {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeAppliedPromoRounding(Cart $cart): void
    {
        foreach ($cart->purchases as $purchase) {
            if ($this->eligibility->lineIsBonusGift($purchase)) {
                continue;
            }
            if (!$this->hasSviatPromoDiscountApplied($purchase)) {
                continue;
            }

            $amount = max(1, (int) ($purchase->amount ?? 0));
            $lineAfter = isset($purchase->meta->total_price)
                ? (float) $purchase->meta->total_price
                : (float) ($purchase->price ?? 0) * $amount;
            $lineBefore = isset($purchase->meta->undiscounted_total_price)
                ? (float) $purchase->meta->undiscounted_total_price
                : (float) ($purchase->undiscounted_price ?? 0) * $amount;

            $lineAfter = $this->roundPrice(max(0.0, $lineAfter));
            $lineBefore = $this->roundPrice(max(0.0, $lineBefore));

            $purchase->meta->total_price = $lineAfter;
            $purchase->price = $this->roundPrice($lineAfter / $amount);

            if (isset($purchase->discounts) && is_array($purchase->discounts)) {
                foreach ($purchase->discounts as $discount) {
                    if (!$discount instanceof Discount || $discount->sign !== 'sviat_promo') {
                        continue;
                    }
                    $discount->priceBeforeDiscount = $this->roundPrice($lineBefore / $amount);
                    $discount->priceAfterDiscount = $this->roundPrice($lineAfter / $amount);
                    $discount->absoluteDiscount = $this->roundPrice(
                        max(0.0, $discount->priceBeforeDiscount - $discount->priceAfterDiscount)
                    );
                    $discount->percentDiscount = $discount->priceBeforeDiscount > 0
                        ? round($discount->absoluteDiscount / ($discount->priceBeforeDiscount / 100), 2)
                        : 0.0;
                }
            }
        }
    }

    private function buildSviatPromoDiscount(object $promo, float $priceBefore, float $priceAfter): Discount
    {
        $discount = new Discount();
        $discount->sign = 'sviat_promo';
        $discount->name = (string) ($promo->name ?? 'Акція');
        $discount->description = 'Promo';
        $discount->priceBeforeDiscount = $this->roundPrice($priceBefore);
        $discount->priceAfterDiscount = $this->roundPrice($priceAfter);
        $discount->absoluteDiscount = $this->roundPrice(max(0.0, $priceBefore - $priceAfter));
        $discount->percentDiscount = $priceBefore > 0
            ? round($discount->absoluteDiscount / ($priceBefore / 100), 2)
            : 0.0;

        return $discount;
    }

    private function appendToCartPurchaseDiscountTotals(Cart $cart, Discount $discount, int $amount): void
    {
        if ($amount < 1) {
            return;
        }

        if (!isset($cart->total_purchases_discounts[$discount->sign])) {
            $discountForTotal = clone $discount;
            $discountForTotal->absoluteDiscount *= $amount;
            $discountForTotal->priceBeforeDiscount *= $amount;
            $discountForTotal->priceAfterDiscount *= $amount;
            $discountForTotal->percentDiscount = $discountForTotal->priceBeforeDiscount > 0
                ? round($discountForTotal->absoluteDiscount / ($discountForTotal->priceBeforeDiscount / 100), 2)
                : 0.0;
            $cart->total_purchases_discounts[$discount->sign] = $discountForTotal;

            return;
        }

        $total = $cart->total_purchases_discounts[$discount->sign];
        $total->absoluteDiscount += $discount->absoluteDiscount * $amount;
        $total->priceBeforeDiscount += $discount->priceBeforeDiscount * $amount;
        $total->priceAfterDiscount += $discount->priceAfterDiscount * $amount;
        $total->percentDiscount = $total->priceBeforeDiscount > 0
            ? round($total->absoluteDiscount / ($total->priceBeforeDiscount / 100), 2)
            : 0.0;
    }

    private function rebuildSviatPromoTotals(Cart $cart): void
    {
        unset($cart->total_purchases_discounts['sviat_promo']);

        foreach ($cart->purchases as $purchase) {
            $amount = max(1, (int) ($purchase->amount ?? 0));
            if (empty($purchase->discounts) || !is_array($purchase->discounts)) {
                continue;
            }
            foreach ($purchase->discounts as $discount) {
                if ($discount instanceof Discount && $discount->sign === 'sviat_promo') {
                    $this->appendToCartPurchaseDiscountTotals($cart, $discount, $amount);
                }
            }
        }
    }

    private function snapshot(object $promo): object
    {
        return (object) [
            'id' => $promo->id,
            'name' => $promo->name,
            'promo_type' => $promo->promo_type,
        ];
    }

    private function appendApplied(object $promo): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!isset($_SESSION[self::SESSION_APPLIED]) || !is_array($_SESSION[self::SESSION_APPLIED])) {
            $_SESSION[self::SESSION_APPLIED] = [];
        }
        $_SESSION[self::SESSION_APPLIED][] = $this->snapshot($promo);
    }

    private function applyBundle2x1(object $promo, Cart $cart): void
    {
        $purchases = $this->eligibility->purchasesInCampaignScope($cart, (int) $promo->id);
        if ($purchases === []) {
            return;
        }

        $unitRows = [];
        $totalUnits = 0;
        foreach ($purchases as $purchase) {
            $amount = (int) $purchase->amount;
            if ($amount < 1) {
                continue;
            }

            $lineTotal = (float) $purchase->meta->total_price;
            if ($lineTotal <= 0) {
                continue;
            }

            $unitPrice = $lineTotal / $amount;
            $unitRows[] = (object) [
                'purchase' => $purchase,
                'amount' => $amount,
                'unit_price' => $unitPrice,
            ];
            $totalUnits += $amount;
        }

        if ($totalUnits < 3 || $unitRows === []) {
            return;
        }

        // 1+1=3: одна безкоштовна одиниця за кожні 3 товари в межах всієї акції.
        $freeUnits = (int) floor($totalUnits / 3);
        if ($freeUnits < 1) {
            return;
        }

        // Безкоштовними робимо найдешевші одиниці у скопі акції.
        usort($unitRows, function ($a, $b) {
            return $a->unit_price <=> $b->unit_price;
        });

        foreach ($unitRows as $row) {
            if ($freeUnits < 1) {
                break;
            }

            $unitsHere = min($freeUnits, (int) $row->amount);
            if ($unitsHere < 1) {
                continue;
            }

            $this->subtractFromPurchase($row->purchase, $unitsHere * (float) $row->unit_price);
            $freeUnits -= $unitsHere;
        }

        $this->appendApplied($promo);
    }

    private function resetBundleHints(Cart $cart): void
    {
        foreach ($cart->purchases as $purchase) {
            if (!isset($purchase->meta) || !is_object($purchase->meta)) {
                $purchase->meta = new \stdClass();
            }
            unset($purchase->meta->sviat_promo_bundle_hint);
        }
    }

    private function attachBundleHint(object $promo, Cart $cart): void
    {
        $purchases = $this->eligibility->purchasesInCampaignScope($cart, (int) $promo->id);
        if ($purchases === []) {
            return;
        }

        $totalUnits = 0;
        foreach ($purchases as $purchase) {
            $totalUnits += max(0, (int) ($purchase->amount ?? 0));
        }
        if ($totalUnits < 1) {
            return;
        }

        $freeUnits = (int) floor($totalUnits / 2);
        $needUnits = $totalUnits % 2 === 0 ? 0 : 1;

        foreach ($purchases as $purchase) {
            if (!isset($purchase->meta) || !is_object($purchase->meta)) {
                $purchase->meta = new \stdClass();
            }
            $purchase->meta->sviat_promo_bundle_hint = (object) [
                'promo_id' => (int) ($promo->id ?? 0),
                'promo_name' => (string) ($promo->name ?? ''),
                'need_units' => $needUnits,
                'free_units' => $freeUnits,
                'is_applied' => $freeUnits > 0,
            ];
        }
    }

    private function subtractFromPurchase(Purchase $purchase, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $line = (float) $purchase->meta->total_price;
        $newLine = max(0.0, $line - $amount);
        $purchase->meta->total_price = $this->roundPrice($newLine);
        $amt = max(1, (int) $purchase->amount);
        $purchase->price = $this->roundPrice($purchase->meta->total_price / $amt);
    }

    private function resolveCurrencyPrecision(): int
    {
        /** @var CurrenciesEntity $currenciesEntity */
        $currenciesEntity = $this->entityFactory->get(CurrenciesEntity::class);
        $mainCurrency = $currenciesEntity->getMainCurrency();

        return max(0, (int) ($mainCurrency->cents ?? 2));
    }

    private function roundPrice(float $price): float
    {
        return round($price, $this->currencyPrecision);
    }
}
