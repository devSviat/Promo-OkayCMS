<?php

namespace Okay\Modules\Sviat\Promo\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\QueryFactory\Select;
use Okay\Helpers\XmlFeedHelper;
use Okay\Modules\Sviat\Promo\Entities\PromoFeedLinkEntity;
use Okay\Modules\Sviat\Promo\Services\PromoFeedPriceResolver;

/**
 * Акційні ціни кампаній для OkayCMS/Feeds.
 *
 * getQuery на конкретних адаптерах пресетів — контекст фіда й preload кампаній.
 * modifyItem — акційна price, базова в compare_price.
 * GoogleMerchantAdapter::getItem / FacebookAdapter::getItem — g:sale_price_effective_date / sale_price_effective_date за періодом кампанії.
 */
class PromoFeedsExtender implements ExtensionInterface
{
    /** @var PromoFeedPriceResolver */
    private $resolver;

    /** @var XmlFeedHelper */
    private $xmlFeedHelper;

    public function __construct(
        PromoFeedPriceResolver $resolver,
        XmlFeedHelper $xmlFeedHelper
    ) {
        $this->resolver      = $resolver;
        $this->xmlFeedHelper = $xmlFeedHelper;
    }

    /** Chain getQuery: контекст фіда (TYPE_FEEDS) і preload кампаній до циклу товарів. */
    public function setFeedContextAndPreload(Select $sql, $feedId): Select
    {
        $this->resolver->setCurrentFeed(PromoFeedLinkEntity::TYPE_FEEDS, (int) $feedId);
        $this->resolver->preload();
        return $sql;
    }

    /** Chain modifyItem: за активної кампанії в фіді — price акційна, compare_price базова. */
    public function attachPromoToProduct(object $item): object
    {
        if (!$this->resolver->isLoaded()) {
            return $item;
        }

        $campaign = $this->resolver->findBestCampaign(
            (int) ($item->product_id ?? 0),
            (int) ($item->brand_id ?? 0),
            (int) ($item->main_category_id ?? 0)
        );

        if ($campaign === null) {
            return $item;
        }

        $basePrice  = (float) ($item->price ?? 0);
        $promoPrice = $this->resolver->computePromoPrice($campaign, $basePrice);

        if ($promoPrice === null) {
            return $item;
        }

        $item->compare_price = $basePrice;
        $item->price         = $promoPrice;
        $item->sviat_promo_feed_applied = 1;

        // Зберігаємо дати акції для g:sale_price_effective_date (GM-пресет у Feeds)
        if (!empty($campaign->has_date_range)) {
            $item->sviat_promo_date_start = !empty($campaign->date_start)
                ? (string) $campaign->date_start
                : null;
            $item->sviat_promo_date_end = !empty($campaign->date_end)
                ? (string) $campaign->date_end
                : null;
        } else {
            $item->sviat_promo_date_start = null;
            $item->sviat_promo_date_end   = null;
        }

        return $item;
    }

    /** Chain GoogleMerchantAdapter::getItem ([$item]): g:sale_price_effective_date за періодом кампанії. */
    public function appendSaleDateToFeedsGMItem(array $result, object $product): array
    {
        if (!$this->resolver->isLoaded() || empty($product->sviat_promo_date_end)) {
            return $result;
        }

        if (!isset($result[0]['data']['g:sale_price'])) {
            return $result;
        }

        $dateStr = $this->resolver->buildSalePriceDateRange(
            $product->sviat_promo_date_start ?? null,
            $product->sviat_promo_date_end
        );

        if ($dateStr !== null) {
            $result[0]['data']['g:sale_price_effective_date']['data'] = $this->xmlFeedHelper->escape($dateStr);
        }

        return $result;
    }

    /** Chain FacebookAdapter::getItem ([$item]): sale_price_effective_date за періодом кампанії. */
    public function appendSaleDateToFeedsFBItem(array $result, object $product): array
    {
        if (!$this->resolver->isLoaded() || empty($product->sviat_promo_date_end)) {
            return $result;
        }

        if (!isset($result[0]['data']['sale_price'])) {
            return $result;
        }

        $dateStr = $this->resolver->buildSalePriceDateRange(
            $product->sviat_promo_date_start ?? null,
            $product->sviat_promo_date_end
        );

        if ($dateStr !== null) {
            $result[0]['data']['sale_price_effective_date']['data'] = $this->xmlFeedHelper->escape($dateStr);
        }

        return $result;
    }

}
