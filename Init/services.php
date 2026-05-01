<?php

use Psr\Log\LoggerInterface;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Core\EntityFactory;
use Okay\Core\Config;
use Okay\Core\FrontTranslations;
use Okay\Core\Image;
use Okay\Core\Money;
use Okay\Core\QueryFactory;
use Okay\Core\Request;
use Okay\Core\Cart;
use Okay\Helpers\ProductsHelper;
use Okay\Helpers\XmlFeedHelper;
use Okay\Modules\Sviat\Promo\Requests\CampaignPayloadRequest;
use Okay\Modules\Sviat\Promo\Helpers\CampaignRepository;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;
use Okay\Modules\Sviat\Promo\Services\CartDiscountPipeline;
use Okay\Modules\Sviat\Promo\Services\PromoFeedPriceResolver;
use Okay\Modules\Sviat\Promo\Services\PromoProductDisplayService;
use Okay\Modules\Sviat\Promo\Extenders\PromoCartHooks;
use Okay\Modules\Sviat\Promo\Extenders\PromoFeedsExtender;
use Okay\Modules\Sviat\Promo\Extenders\PromoGoogleMerchantExtender;
use Okay\Modules\Sviat\Promo\Extenders\PromoProductsExtender;

return [
    CampaignPayloadRequest::class => [
        'class'     => CampaignPayloadRequest::class,
        'arguments' => [
            new SR(Request::class),
        ],
    ],
    CampaignRepository::class => [
        'class'     => CampaignRepository::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Config::class),
            new SR(Image::class),
        ],
    ],
    PromotionEligibility::class => [
        'class'     => PromotionEligibility::class,
        'arguments' => [
            new SR(EntityFactory::class),
        ],
    ],
    CartDiscountPipeline::class => [
        'class'     => CartDiscountPipeline::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(PromotionEligibility::class),
        ],
    ],
    PromoProductDisplayService::class => [
        'class'     => PromoProductDisplayService::class,
        'arguments' => [
            new SR(PromotionEligibility::class),
            new SR(LoggerInterface::class),
            new SR(EntityFactory::class),
        ],
    ],
    PromoProductsExtender::class => [
        'class'     => PromoProductsExtender::class,
        'arguments' => [
            new SR(PromoProductDisplayService::class),
        ],
    ],
    PromoCartHooks::class => [
        'class'     => PromoCartHooks::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(ProductsHelper::class),
            new SR(Cart::class),
            new SR(CartDiscountPipeline::class),
            new SR(PromotionEligibility::class),
            new SR(FrontTranslations::class),
        ],
    ],

    PromoFeedPriceResolver::class => [
        'class'     => PromoFeedPriceResolver::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(QueryFactory::class),
            new SR(PromotionEligibility::class),
        ],
    ],

    PromoFeedsExtender::class => [
        'class'     => PromoFeedsExtender::class,
        'arguments' => [
            new SR(PromoFeedPriceResolver::class),
            new SR(XmlFeedHelper::class),
        ],
    ],

    PromoGoogleMerchantExtender::class => [
        'class'     => PromoGoogleMerchantExtender::class,
        'arguments' => [
            new SR(PromoFeedPriceResolver::class),
            new SR(PromotionEligibility::class),
            new SR(Money::class),
            new SR(XmlFeedHelper::class),
            new SR(EntityFactory::class),
        ],
    ],
];
