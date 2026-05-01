<?php

namespace Okay\Modules\Sviat\Promo\Init;

use Okay\Core\Cart;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\EntityField;
use Okay\Entities\ProductsEntity;
use Okay\Entities\PurchasesEntity;
use Okay\Helpers\CartHelper;
use Okay\Helpers\DeliveriesHelper;
use Okay\Helpers\DiscountsHelper;
use Okay\Helpers\ProductsHelper;
use Okay\Modules\OkayCMS\Feeds\Core\Presets\AbstractPresetAdapter;
use Okay\Modules\OkayCMS\Feeds\Core\Presets\Adapters\EpitsentrAdapter;
use Okay\Modules\OkayCMS\Feeds\Core\Presets\Adapters\FacebookAdapter;
use Okay\Modules\OkayCMS\Feeds\Core\Presets\Adapters\GoogleMerchantAdapter;
use Okay\Modules\OkayCMS\Feeds\Core\Presets\Adapters\HotlineAdapter;
use Okay\Modules\OkayCMS\Feeds\Core\Presets\Adapters\PriceUaAdapter;
use Okay\Modules\OkayCMS\Feeds\Core\Presets\Adapters\PromUaAdapter;
use Okay\Modules\OkayCMS\Feeds\Core\Presets\Adapters\RozetkaAdapter;
use Okay\Modules\OkayCMS\Feeds\Core\Presets\Adapters\YmlAdapter;
use Okay\Modules\OkayCMS\GoogleMerchant\Helpers\GoogleMerchantHelper;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoFeedLinkEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoRewardLineEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoScopeEntity;
use Okay\Modules\Sviat\Promo\ExtendsEntities\ProductsPromoFilter;
use Okay\Modules\Sviat\Promo\Extenders\PromoCampaignCacheInvalidator;
use Okay\Modules\Sviat\Promo\Extenders\PromoCartHooks;
use Okay\Modules\Sviat\Promo\Extenders\PromoFeedsExtender;
use Okay\Modules\Sviat\Promo\Extenders\PromoGoogleMerchantExtender;
use Okay\Modules\Sviat\Promo\Extenders\PromoProductsExtender;

class Init extends AbstractInit
{
    public const PERMISSION = 'sviat__promo';

    public function install(): void
    {
        if (!is_dir('files/originals/promo')) {
            mkdir('files/originals/promo', 0755, true);
        }
        if (!is_dir('files/resized/promo')) {
            mkdir('files/resized/promo', 0755, true);
        }

        $this->setBackendMainController('CampaignListAdmin');

        $this->migrateEntityTable(PromoCampaignEntity::class, [
            (new EntityField('id'))->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
            (new EntityField('name'))->setTypeVarchar(255)->setDefault('')->setIsLang(),
            (new EntityField('url'))->setTypeVarchar(255)->setDefault('')->setIndex(100),
            (new EntityField('meta_title'))->setTypeVarchar(512)->setDefault('')->setIsLang(),
            (new EntityField('meta_keywords'))->setTypeVarchar(512)->setDefault('')->setIsLang(),
            (new EntityField('meta_description'))->setTypeVarchar(512)->setDefault('')->setIsLang(),
            (new EntityField('annotation'))->setTypeText()->setIsLang(),
            (new EntityField('description'))->setTypeText()->setIsLang(),
            (new EntityField('image'))->setTypeVarchar(255)->setDefault(''),
            (new EntityField('image_width'))->setTypeInt(11, true)->setDefault(PromoCampaignEntity::DEFAULT_IMAGE_WIDTH),
            (new EntityField('image_height'))->setTypeInt(11, true)->setDefault(PromoCampaignEntity::DEFAULT_IMAGE_HEIGHT),
            (new EntityField('image_mobile'))->setTypeVarchar(255)->setDefault(''),
            (new EntityField('image_mobile_width'))->setTypeInt(11, true)->setDefault(PromoCampaignEntity::DEFAULT_IMAGE_MOBILE_WIDTH),
            (new EntityField('image_mobile_height'))->setTypeInt(11, true)->setDefault(PromoCampaignEntity::DEFAULT_IMAGE_MOBILE_HEIGHT),
            (new EntityField('badge_image'))->setTypeVarchar(255)->setDefault(''),
            (new EntityField('caption_banner_image'))->setTypeVarchar(255)->setDefault(''),
            (new EntityField('caption_banner_width'))->setTypeInt(11, true)->setDefault(PromoCampaignEntity::DEFAULT_CAPTION_BANNER_WIDTH),
            (new EntityField('caption_banner_height'))->setTypeInt(11, true)->setDefault(PromoCampaignEntity::DEFAULT_CAPTION_BANNER_HEIGHT),
            (new EntityField('product_caption_mode'))->setTypeTinyInt(1, true)->setDefault(0),
            (new EntityField('promo_type'))->setTypeEnum([
                PromoCampaignEntity::TYPE_PERCENT,
                PromoCampaignEntity::TYPE_FIXED,
                PromoCampaignEntity::TYPE_GIFT,
                PromoCampaignEntity::TYPE_BUNDLE_3X2,
                PromoCampaignEntity::TYPE_FREE_SHIPPING,
            ], false)->setDefault(PromoCampaignEntity::TYPE_PERCENT)->setIndex(),
            (new EntityField('min_order_amount'))->setTypeDecimal(14, 4)->setDefault(0),
            (new EntityField('discount_percent'))->setTypeDecimal(10, 2)->setNullable(),
            (new EntityField('discount_fixed'))->setTypeDecimal(14, 4)->setNullable(),
            (new EntityField('has_date_range'))->setTypeTinyInt(1, true)->setDefault(0)->setIndex(),
            (new EntityField('date_start'))->setTypeDatetime()->setNullable()->setIndex(),
            (new EntityField('date_end'))->setTypeDatetime()->setNullable()->setIndex(),
            (new EntityField('feed_enabled'))->setTypeTinyInt(1, true)->setDefault(0)->setIndex(),
            (new EntityField('visible'))->setTypeTinyInt(1, true)->setDefault(0)->setIndex(),
            (new EntityField('priority'))->setTypeInt(11, true)->setDefault(0)->setIndex(),
            (new EntityField('position'))->setTypeInt(11, true)->setDefault(0)->setIndex(),
            (new EntityField('last_modify'))->setTypeTimestamp(),
        ]);

        $this->migrateEntityTable(PromoRewardLineEntity::class, [
            (new EntityField('promo_id'))->setTypeInt(11)->setIndex(),
            (new EntityField('gift_id'))->setTypeInt(11)->setIndex(),
            (new EntityField('position'))->setTypeInt(11),
        ]);

        $this->migrateEntityTable(PromoScopeEntity::class, [
            (new EntityField('promo_id'))->setTypeInt(11)->setIndex(),
            (new EntityField('object_id'))->setTypeInt(11)->setIndex(),
            (new EntityField('type'))->setTypeEnum(['product', 'category', 'brand', 'feature_value'])->setDefault('category')->setIndex(),
            (new EntityField('feature_id'))->setTypeInt(11)->setNullable()->setIndex(),
            (new EntityField('exclude'))->setTypeTinyInt(1, true)->setDefault(0)->setIndex(),
        ]);

        $this->migrateEntityTable(PromoFeedLinkEntity::class, [
            (new EntityField('promo_id'))->setTypeInt(11)->setIndex(),
            (new EntityField('feed_type'))->setTypeEnum([
                PromoFeedLinkEntity::TYPE_FEEDS,
                PromoFeedLinkEntity::TYPE_GM,
            ])->setDefault(PromoFeedLinkEntity::TYPE_FEEDS)->setIndex(),
            (new EntityField('feed_id'))->setTypeInt(11)->setIndex(),
        ]);

        $this->migrateEntityField(
            PurchasesEntity::class,
            (new EntityField('gift_product_id'))->setTypeInt(11)->setNullable()
        );
    }

    public function init(): void
    {
        $this->registerEntityField(PurchasesEntity::class, 'gift_product_id');
        $this->registerEntityField(PromoCampaignEntity::class, 'badge_image');
        $this->registerEntityField(PromoCampaignEntity::class, 'image_mobile');
        $this->registerEntityField(PromoCampaignEntity::class, 'caption_banner_image');
        $this->registerEntityField(PromoCampaignEntity::class, 'image_width');
        $this->registerEntityField(PromoCampaignEntity::class, 'image_height');
        $this->registerEntityField(PromoCampaignEntity::class, 'image_mobile_width');
        $this->registerEntityField(PromoCampaignEntity::class, 'image_mobile_height');
        $this->registerEntityField(PromoCampaignEntity::class, 'caption_banner_width');
        $this->registerEntityField(PromoCampaignEntity::class, 'caption_banner_height');
        $this->registerEntityField(PromoCampaignEntity::class, 'product_caption_mode');
        $this->registerEntityField(PromoCampaignEntity::class, 'feed_enabled');

        $this->registerBackendController('CampaignListAdmin');
        $this->registerBackendController('CampaignEditAdmin');
        $this->addBackendControllerPermission('CampaignListAdmin', self::PERMISSION);
        $this->addBackendControllerPermission('CampaignEditAdmin', self::PERMISSION);

        $this->addResizeObject('promo_images_dir', 'resized_promo_images_dir');

        $this->extendUpdateObject('Sviat.Promo.PromoCampaignEntity', self::PERMISSION, PromoCampaignEntity::class);
        $this->extendUpdateObject('Sviat.Promo.PromoRewardLineEntity', self::PERMISSION, PromoRewardLineEntity::class);
        $this->extendUpdateObject('Sviat.Promo.PromoScopeEntity', self::PERMISSION, PromoScopeEntity::class);
        $this->extendUpdateObject('Sviat.Promo.PromoFeedLinkEntity', self::PERMISSION, PromoFeedLinkEntity::class);

        $this->extendBackendMenu('left_catalog', [
            'sviat_promo__menu_title' => [
                'CampaignListAdmin',
                'CampaignEditAdmin'
            ],
        ]);

        $this->registerEntityFilter(
            ProductsEntity::class,
            'in_campaign',
            ProductsPromoFilter::class,
            'forCampaignScope'
        );
        $this->registerEntityFilter(
            ProductsEntity::class,
            'discounted',
            ProductsPromoFilter::class,
            'forDiscounted'
        );
        $this->registerEntityFilter(
            ProductsEntity::class,
            'other_filter',
            ProductsPromoFilter::class,
            'forOtherFilter'
        );

        $this->registerPurchaseDiscountSign(
            'sviat_promo',
            'discount_sviat_promo_name',
            'discount_sviat_promo_description'
        );

        // Хуки кошика та знижок
        $this->registerChainExtension(
            ['class' => Cart::class, 'method' => 'attachDiscounts'],
            ['class' => PromoCartHooks::class, 'method' => 'attachSviatPromoPurchaseDiscounts']
        );

        $this->registerChainExtension(
            ['class' => DiscountsHelper::class, 'method' => 'getPurchaseSets'],
            ['class' => PromoCartHooks::class, 'method' => 'prependSviatPromoPurchaseSet']
        );

        $this->registerChainExtension(
            ['class' => ProductsHelper::class, 'method' => 'getList'],
            ['class' => PromoProductsExtender::class, 'method' => 'decorateListProducts']
        );

        $this->registerChainExtension(
            ['class' => ProductsHelper::class, 'method' => 'attachProductData'],
            ['class' => PromoProductsExtender::class, 'method' => 'decorateProductAfterAttach']
        );

        $this->registerChainExtension(
            ['class' => Cart::class, 'method' => 'applyPurchasesDiscounts'],
            ['class' => PromoCartHooks::class, 'method' => 'applySviatPromosToPurchases']
        );

        $this->registerChainExtension(
            ['class' => Cart::class, 'method' => 'get'],
            ['class' => PromoCartHooks::class, 'method' => 'addPromoGiftPurchases']
        );

        $this->registerChainExtension(
            ['class' => Cart::class, 'method' => 'deletePurchase'],
            ['class' => PromoCartHooks::class, 'method' => 'removePromoGiftPurchases']
        );

        $this->registerChainExtension(
            ['class' => CartHelper::class, 'method' => 'prepareCart'],
            ['class' => PromoCartHooks::class, 'method' => 'getPromoGiftPurchases']
        );

        $this->registerChainExtension(
            ['class' => DeliveriesHelper::class, 'method' => 'getCartDeliveriesList'],
            ['class' => PromoCartHooks::class, 'method' => 'applyFreeShippingToCartDeliveries']
        );

        $this->registerChainExtension(
            ['class' => DeliveriesHelper::class, 'method' => 'prepareDeliveryPriceInfo'],
            ['class' => PromoCartHooks::class, 'method' => 'applyFreeShippingToOrderDelivery']
        );

        $this->registerChainExtension(
            ['class' => Cart::class, 'method' => 'clear'],
            ['class' => PromoCartHooks::class, 'method' => 'clearSviatPromoSession']
        );

        // Sviat/Redis cache invalidation when promo campaign or scope changes.
        $this->registerQueueExtension(
            ['class' => PromoCampaignEntity::class, 'method' => 'add'],
            ['class' => PromoCampaignCacheInvalidator::class, 'method' => 'onCampaignAdd']
        );
        $this->registerQueueExtension(
            ['class' => PromoCampaignEntity::class, 'method' => 'update'],
            ['class' => PromoCampaignCacheInvalidator::class, 'method' => 'onCampaignUpdate']
        );
        $this->registerQueueExtension(
            ['class' => PromoCampaignEntity::class, 'method' => 'delete'],
            ['class' => PromoCampaignCacheInvalidator::class, 'method' => 'onCampaignDelete']
        );
        $this->registerQueueExtension(
            ['class' => PromoScopeEntity::class, 'method' => 'add'],
            ['class' => PromoCampaignCacheInvalidator::class, 'method' => 'onScopeAdd']
        );
        $this->registerQueueExtension(
            ['class' => PromoScopeEntity::class, 'method' => 'update'],
            ['class' => PromoCampaignCacheInvalidator::class, 'method' => 'onScopeUpdate']
        );
        $this->registerQueueExtension(
            ['class' => PromoScopeEntity::class, 'method' => 'delete'],
            ['class' => PromoCampaignCacheInvalidator::class, 'method' => 'onScopeDelete']
        );

        // OkayCMS/Feeds
        $feedPreloadHook = ['class' => PromoFeedsExtender::class, 'method' => 'setFeedContextAndPreload'];
        foreach ([
            YmlAdapter::class,
            FacebookAdapter::class,
            GoogleMerchantAdapter::class,
            HotlineAdapter::class,
            PriceUaAdapter::class,
            PromUaAdapter::class,
            RozetkaAdapter::class,
            EpitsentrAdapter::class,
        ] as $adapterClass) {
            if (class_exists($adapterClass) && method_exists($adapterClass, 'getQuery')) {
                $this->registerChainExtension(
                    ['class' => $adapterClass, 'method' => 'getQuery'],
                    $feedPreloadHook
                );
            }
        }

        if (class_exists(AbstractPresetAdapter::class) && method_exists(AbstractPresetAdapter::class, 'modifyItem')) {
            $this->registerChainExtension(
                ['class' => AbstractPresetAdapter::class, 'method' => 'modifyItem'],
                ['class' => PromoFeedsExtender::class, 'method' => 'attachPromoToProduct']
            );
        }

        if (class_exists(GoogleMerchantAdapter::class) && method_exists(GoogleMerchantAdapter::class, 'getItem')) {
            $this->registerChainExtension(
                ['class' => GoogleMerchantAdapter::class, 'method' => 'getItem'],
                ['class' => PromoFeedsExtender::class, 'method' => 'appendSaleDateToFeedsGMItem']
            );
        }

        if (class_exists(FacebookAdapter::class) && method_exists(FacebookAdapter::class, 'getItem')) {
            $this->registerChainExtension(
                ['class' => FacebookAdapter::class, 'method' => 'getItem'],
                ['class' => PromoFeedsExtender::class, 'method' => 'appendSaleDateToFeedsFBItem']
            );
        }

        // OkayCMS/GoogleMerchant
        if (class_exists(GoogleMerchantHelper::class)) {
            if (method_exists(GoogleMerchantHelper::class, 'getQuery')) {
                $this->registerChainExtension(
                    ['class' => GoogleMerchantHelper::class, 'method' => 'getQuery'],
                    ['class' => PromoGoogleMerchantExtender::class, 'method' => 'preloadForFeed']
                );
            }

            if (method_exists(GoogleMerchantHelper::class, 'getItem')) {
                $this->registerChainExtension(
                    ['class' => GoogleMerchantHelper::class, 'method' => 'getItem'],
                    ['class' => PromoGoogleMerchantExtender::class, 'method' => 'applyPromoToItem']
                );
            }
        }
    }

    public function update_1_0_2(): void
    {
        $this->migrateEntityField(
            PromoCampaignEntity::class,
            (new EntityField('image_width'))->setTypeInt(11, true)->setDefault(PromoCampaignEntity::DEFAULT_IMAGE_WIDTH)
        );
        $this->migrateEntityField(
            PromoCampaignEntity::class,
            (new EntityField('image_height'))->setTypeInt(11, true)->setDefault(PromoCampaignEntity::DEFAULT_IMAGE_HEIGHT)
        );
        $this->migrateEntityField(
            PromoCampaignEntity::class,
            (new EntityField('image_mobile_width'))->setTypeInt(11, true)->setDefault(PromoCampaignEntity::DEFAULT_IMAGE_MOBILE_WIDTH)
        );
        $this->migrateEntityField(
            PromoCampaignEntity::class,
            (new EntityField('image_mobile_height'))->setTypeInt(11, true)->setDefault(PromoCampaignEntity::DEFAULT_IMAGE_MOBILE_HEIGHT)
        );
        $this->migrateEntityField(
            PromoCampaignEntity::class,
            (new EntityField('caption_banner_width'))->setTypeInt(11, true)->setDefault(PromoCampaignEntity::DEFAULT_CAPTION_BANNER_WIDTH)
        );
        $this->migrateEntityField(
            PromoCampaignEntity::class,
            (new EntityField('caption_banner_height'))->setTypeInt(11, true)->setDefault(PromoCampaignEntity::DEFAULT_CAPTION_BANNER_HEIGHT)
        );
    }
}
