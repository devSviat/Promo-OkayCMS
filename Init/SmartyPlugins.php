<?php

use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Helpers\ProductsHelper;
use Okay\Modules\Sviat\Promo\Plugins\ProductCampaignBlockPlugin;
use Okay\Modules\Sviat\Promo\Plugins\GiftBadgePlugin;
use Okay\Modules\Sviat\Promo\Plugins\PromoBadgeStickerPlugin;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;

return [
    ProductCampaignBlockPlugin::class => [
        'class' => ProductCampaignBlockPlugin::class,
        'arguments' => [
            new SR(Design::class),
            new SR(EntityFactory::class),
            new SR(ProductsHelper::class),
            new SR(PromotionEligibility::class),
        ],
    ],
    GiftBadgePlugin::class => [
        'class' => GiftBadgePlugin::class,
        'arguments' => [
            new SR(Design::class),
            new SR(EntityFactory::class),
            new SR(ProductsHelper::class),
            new SR(PromotionEligibility::class),
        ],
    ],
    PromoBadgeStickerPlugin::class => [
        'class' => PromoBadgeStickerPlugin::class,
        'arguments' => [
            new SR(Design::class),
        ],
    ],
];
