<?php

namespace Okay\Modules\Sviat\Promo\Services;

use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;

/**
 * Ціна одиниці зі знижкою відсоткової чи фіксованої акції — так, як її показує вітрина.
 * Одна формула для сервісу вітрини й блоку акції на сторінці товару.
 */
final readonly class PromoUnitOffer
{
    private function __construct(
        public float $price,
        public float $percent,
    ) {
    }

    /**
     * @param float $basePrice ціна варіанта в основній валюті до знижки цієї акції
     * @return self|null null, якщо акція не дає знижки для цієї ціни
     */
    public static function of(object $campaign, float $basePrice, int $currencyPrecision): ?self
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

            return new self(PromoPriceRounding::price($campaign, $basePrice * (100 - $pct) / 100, $currencyPrecision, $basePrice), $pct);
        }

        if ($type === PromoCampaignEntity::TYPE_FIXED) {
            $fixed = (float) ($campaign->discount_fixed ?? 0);
            if ($fixed <= 0 || $basePrice < $fixed) {
                return null;
            }

            return new self(PromoPriceRounding::price($campaign, $basePrice - $fixed, $currencyPrecision, $basePrice), round(($fixed / $basePrice) * 100, 2));
        }

        return null;
    }
}
