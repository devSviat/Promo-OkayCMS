<?php

use Okay\Modules\Sviat\Promo\Controllers\CampaignLandingController;
use Okay\Modules\Sviat\Promo\Controllers\PromoCatalogController;
use Okay\Modules\Sviat\Promo\Controllers\GiftSelectionEndpoint;

return [
    'sviat_promo_page' => [
        'slug' => 'promo/{$url}',
        'params' => [
            'controller' => CampaignLandingController::class,
            'method' => 'render',
        ],
    ],
    'sviat_promo_list' => [
        'slug' => 'promo',
        'params' => [
            'controller' => PromoCatalogController::class,
            'method' => 'render',
        ],
    ],
    'sviat_ajax_promo_cart' => [
        'slug' => '/ajax/sviat_promo_cart',
        'params' => [
            'controller' => GiftSelectionEndpoint::class,
            'method' => 'ajaxAddGiftToCart',
        ],
        'to_front' => true,
    ],
];
