<?php

namespace Okay\Modules\Sviat\Promo\Services;

/** Чиста функція визначення відсотка: діє найбільший досягнутий поріг. */
final class QuantityTierCalculator
{
    /** @param array<int, object|array<string, mixed>> $tiers */
    public static function percentForQuantity(array $tiers, int $quantity): float
    {
        if ($quantity < 2) {
            return 0.0;
        }
        $bestThreshold = 0;
        $result = 0.0;
        foreach ($tiers as $row) {
            $min = (int) (is_array($row) ? ($row['min_quantity'] ?? 0) : ($row->min_quantity ?? 0));
            $percent = (float) (is_array($row) ? ($row['percent'] ?? 0) : ($row->percent ?? 0));
            if ($min >= 2 && $min <= $quantity && $min > $bestThreshold && $percent > 0 && $percent <= 100) {
                $bestThreshold = $min;
                $result = $percent;
            }
        }
        return $result;
    }
}
