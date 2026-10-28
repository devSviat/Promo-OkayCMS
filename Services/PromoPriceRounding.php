<?php

namespace Okay\Modules\Sviat\Promo\Services;

/**
 * Єдина політика округлення кінцевої ціни одиниці товару за акцією.
 * За замовчуванням — чинна точність основної валюти; опційно — ціла одиниця.
 * Відсоток або фіксовану суму знижки НЕ округляємо.
 */
final class PromoPriceRounding
{
    public static function enabled(object $campaign): bool
    {
        return (int) ($campaign->round_final_price ?? 0) === 1;
    }

    public static function price(object $campaign, float $discountedUnitPrice, int $currencyPrecision, ?float $baseUnitPrice = null): float
    {
        $roundedToInteger = self::enabled($campaign);
        $precision = $roundedToInteger ? 0 : max(0, $currencyPrecision);
        $result = round(max(0.0, $discountedUnitPrice), $precision, PHP_ROUND_HALF_UP);
        if ($roundedToInteger && $baseUnitPrice !== null && $result > $baseUnitPrice) {
            // Маленька знижка не повинна ПІДВИЩИТИ ціну після округлення вгору.
            $result = max(0.0, floor($baseUnitPrice));
        }
        return $result;
    }
}
