<?php

namespace Okay\Modules\Sviat\Promo\Extenders;

use Okay\Core\EntityFactory;
use Okay\Core\Money;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\QueryFactory\Select;
use Okay\Entities\CurrenciesEntity;
use Okay\Helpers\XmlFeedHelper;
use Okay\Modules\Sviat\Promo\Entities\PromoFeedLinkEntity;
use Okay\Modules\Sviat\Promo\Services\PromoFeedPriceResolver;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;

/**
 * Акційні ціни кампаній для OkayCMS/GoogleMerchant (legacy).
 *
 * GoogleMerchantHelper::getQuery — контекст фіда, preload кампаній і валют; getItem — акційна ціна та sale_price_effective_date.
 */
class PromoGoogleMerchantExtender implements ExtensionInterface
{
    /** @var PromoFeedPriceResolver */
    private $resolver;

    /** @var PromotionEligibility */
    private $eligibility;

    /** @var Money */
    private $money;

    /** @var XmlFeedHelper */
    private $feedHelper;

    /** @var EntityFactory */
    private $entityFactory;

    /** @var object|null */
    private $mainCurrency = null;

    /** @var array */
    private $allCurrencies = [];

    public function __construct(
        PromoFeedPriceResolver $resolver,
        PromotionEligibility   $eligibility,
        Money                  $money,
        XmlFeedHelper          $feedHelper,
        EntityFactory          $entityFactory
    ) {
        $this->resolver      = $resolver;
        $this->eligibility   = $eligibility;
        $this->money         = $money;
        $this->feedHelper    = $feedHelper;
        $this->entityFactory = $entityFactory;
    }

    /**
     * Хук GoogleMerchantHelper::getQuery (chain).
     *
     * Встановлює контекст поточного фіда (type=gm, id=$feedId) і до циклу
     * товарів завантажує кампанії та валюти.
     */
    public function preloadForFeed(Select $sql, $feedId): Select
    {
        $this->resolver->setCurrentFeed(PromoFeedLinkEntity::TYPE_GM, (int) $feedId);
        $this->resolver->preload();

        /** @var CurrenciesEntity $currenciesEntity */
        $currenciesEntity    = $this->entityFactory->get(CurrenciesEntity::class);
        $this->mainCurrency  = $currenciesEntity->getMainCurrency();
        $this->allCurrencies = $currenciesEntity->mappedBy('id')->find();

        return $sql;
    }

    /**
     * Хук GoogleMerchantHelper::getItem (chain).
     *
     * У `g:price` повертає базову ціну, а в `g:sale_price` — акційну.
     * Якщо є діапазон дат, додає `g:sale_price_effective_date`.
     */
    public function applyPromoToItem(array $result, $product): array
    {
        if (!$this->resolver->isLoaded()) {
            return $result;
        }

        $campaign = $this->resolver->findBestCampaign(
            (int) ($product->product_id ?? 0),
            (int) ($product->brand_id ?? 0),
            (int) ($product->main_category_id ?? 0)
        );

        if ($campaign === null) {
            return $result;
        }

        if (
            !$this->eligibility->campaignVisibleOnStorefront($campaign)
            || !$this->eligibility->campaignDatesOk($campaign)
        ) {
            return $result;
        }

        $basePrice  = (float) ($product->price ?? 0);
        $promoPrice = $this->resolver->computePromoPrice($campaign, $basePrice);

        if ($promoPrice === null) {
            return $result;
        }

        $convertedBase  = $this->convertToMainCurrency($basePrice, $product->currency_id);
        $convertedPromo = $this->convertToMainCurrency($promoPrice, $product->currency_id);

        if ($convertedBase === null || $convertedPromo === null || $this->mainCurrency === null) {
            return $result;
        }

        $convertedBase  = $this->money->convert($convertedBase, $this->mainCurrency->id, false);
        $convertedPromo = $this->money->convert($convertedPromo, $this->mainCurrency->id, false);

        $code = $this->mainCurrency->code;

        $result['g:price']['data']      = $this->feedHelper->escape($convertedBase . ' ' . $code);
        $result['g:sale_price']['data'] = $this->feedHelper->escape($convertedPromo . ' ' . $code);

        // g:sale_price_effective_date — лише при явному періоді (як у Feeds / вимоги Google).
        if (!empty($campaign->has_date_range) && !empty($campaign->date_end)) {
            $dateStr = $this->resolver->buildSalePriceDateRange(
                !empty($campaign->date_start) ? (string) $campaign->date_start : null,
                (string) $campaign->date_end
            );
            if ($dateStr !== null) {
                $result['g:sale_price_effective_date']['data'] = $this->feedHelper->escape($dateStr);
            }
        }

        return $result;
    }

    private function convertToMainCurrency(float $price, $currencyId): ?float
    {
        if (empty($currencyId) || !isset($this->allCurrencies[$currencyId])) {
            return $price;
        }
        $c = $this->allCurrencies[$currencyId];
        if ((float) $c->rate_from === (float) $c->rate_to) {
            return $price;
        }
        return round($price * $c->rate_to / $c->rate_from, 2);
    }
}
