<?php

namespace Okay\Modules\Sviat\Promo\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\BackendTranslations;
use Okay\Core\EntityFactory;
use Okay\Entities\BrandsEntity;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\ImagesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Entities\FeaturesEntity;
use Okay\Entities\FeaturesValuesEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoRewardLineEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoScopeEntity;
use Okay\Modules\Sviat\Promo\Helpers\CampaignRepository;
use Okay\Modules\Sviat\Promo\Requests\CampaignPayloadRequest;

class CampaignEditAdmin extends IndexAdmin
{
    public function fetch(
        EntityFactory        $entityFactory,
        CampaignPayloadRequest $payload,
        CampaignRepository   $campaignRepository,
        BackendTranslations  $backendTranslations
    ) {
        if ($this->request->get('action') === 'feature_values') {
            $this->response->setContent(
                json_encode(['items' => $this->getFeatureValuesForAjax($entityFactory)], JSON_UNESCAPED_UNICODE),
                RESPONSE_JSON
            );
            return;
        }

        $campaigns      = $entityFactory->get(PromoCampaignEntity::class);
        $rewardLines    = $entityFactory->get(PromoRewardLineEntity::class);
        $scopeRows      = $entityFactory->get(PromoScopeEntity::class);
        $productsEntity = $entityFactory->get(ProductsEntity::class);
        $categoriesEntity = $entityFactory->get(CategoriesEntity::class);
        $brandsEntity   = $entityFactory->get(BrandsEntity::class);
        $imagesEntity   = $entityFactory->get(ImagesEntity::class);

        $promoGifts  = [];
        $promoObjects = [];

        if ($this->request->method('post')) {
            $promo       = $payload->postPromo();
            $promoGifts  = $payload->postPromoGift($promo);
            $rawObjects  = $payload->postPromoObject();
            $promoObjects = $this->normalizePromoObjects($rawObjects);

            if (empty($promo->name)) {
                $this->design->assign('message_error', 'empty_name');
            } elseif (empty($promo->url)) {
                $this->design->assign('message_error', 'empty_url');
            } elseif (($a = $campaignRepository->findPromos(['url' => $promo->url, 'admin_list' => 1])) && $a[0]->id != $promo->id) {
                $this->design->assign('message_error', 'url_exists');
            } elseif (substr($promo->url, -1) == '-' || substr($promo->url, 0, 1) == '-') {
                $this->design->assign('message_error', 'url_wrong');
            } elseif ($promo->promo_type === PromoCampaignEntity::TYPE_GIFT && !$promoGifts) {
                $this->design->assign('message_error', 'empty_promo_gifts');
            } elseif (!$this->hasAnyObject($promoObjects)) {
                $this->design->assign('message_error', 'empty_promo_objects');
            } elseif ($promo->promo_type === PromoCampaignEntity::TYPE_PERCENT && ($promo->discount_percent === null || $promo->discount_percent <= 0)) {
                $this->design->assign('message_error', 'empty_discount_percent');
            } elseif ($promo->promo_type === PromoCampaignEntity::TYPE_FIXED && ($promo->discount_fixed === null || $promo->discount_fixed <= 0)) {
                $this->design->assign('message_error', 'empty_discount_fixed');
            } else {
                if (empty($promo->id)) {
                    $promo->id = $campaignRepository->add($promo);
                    $this->postRedirectGet->storeMessageSuccess('added');
                    $this->postRedirectGet->storeNewEntityId($promo->id);
                    $isNew = true;
                } else {
                    $campaignRepository->update($promo->id, $promo);
                    $this->postRedirectGet->storeMessageSuccess('updated');
                    $isNew = false;
                }

                if ($payload->postDeleteImage()) {
                    $campaignRepository->deleteImage($promo);
                }
                if ($image = $payload->fileImage()) {
                    $campaignRepository->uploadImage($image, $promo, $isNew);
                }

                if ($payload->postDeleteMobileImage()) {
                    $campaignRepository->deletePromoImageField('image_mobile', $promo);
                }
                if ($mobileImage = $payload->fileMobileImage()) {
                    $campaignRepository->uploadPromoImageField('image_mobile', $mobileImage, $promo, $isNew);
                }

                if ($payload->postDeleteBadgeImage()) {
                    $campaignRepository->deletePromoImageField('badge_image', $promo);
                }
                if ($badgeFile = $payload->fileBadgeImage()) {
                    $campaignRepository->uploadPromoImageField('badge_image', $badgeFile, $promo, $isNew);
                }

                if ($payload->postDeleteCaptionBannerImage()) {
                    $campaignRepository->deletePromoImageField('caption_banner_image', $promo);
                }
                if ($captionBannerFile = $payload->fileCaptionBannerImage()) {
                    $campaignRepository->uploadPromoImageField('caption_banner_image', $captionBannerFile, $promo, $isNew);
                }

                $campaignRepository->deleteGift($promo->id);
                if (is_array($promoGifts)) {
                    $pos = 0;
                    foreach ($promoGifts as $gift) {
                        $rewardLines->add([
                            'promo_id' => $promo->id,
                            'gift_id'  => $gift->gift_id,
                            'position' => $pos++,
                        ]);
                    }
                }
                $promo = $campaigns->get($promo->id);

                $campaignRepository->deleteObject($promo->id);
                foreach (['include' => 0, 'exclude' => 1] as $mode => $excludeFlag) {
                    if (empty($promoObjects[$mode])) {
                        continue;
                    }
                    foreach (['category', 'brand', 'product'] as $type) {
                        if (empty($promoObjects[$mode][$type])) {
                            continue;
                        }
                        foreach ($promoObjects[$mode][$type] as $oid) {
                            if ($oid === '' || $oid === null) {
                                continue;
                            }
                            $scopeRows->add([
                                'promo_id'  => $promo->id,
                                'object_id' => (int) $oid,
                                'type'      => $type,
                                'exclude'   => $excludeFlag,
                            ]);
                        }
                    }
                    foreach ($promoObjects[$mode]['feature_value'] ?? [] as $featureId => $valueIds) {
                        foreach ($valueIds as $valueId) {
                            $scopeRows->add([
                                'promo_id'   => $promo->id,
                                'object_id'  => $valueId,
                                'feature_id' => (int) $featureId,
                                'type'       => 'feature_value',
                                'exclude'    => $excludeFlag,
                            ]);
                        }
                    }
                }

                // Зберігаємо прив'язки до фідів
                $campaignRepository->saveFeedLinks(
                    (int) $promo->id,
                    (int) ($promo->feed_enabled ?? 0),
                    $payload->postFeedIds()
                );

                $promoObjects = $scopeRows->find(['promo_id' => $promo->id]);
                $this->postRedirectGet->redirect();
            }
        } else {
            $promoId = $this->request->get('id', 'integer');
            if ($promoId) {
                $promo = $campaignRepository->getPromo($promoId);
                if (!empty($promo->id)) {
                    $promoGifts  = $rewardLines->find(['promo_id' => $promo->id]);
                    $promoObjects = $scopeRows->find(['promo_id' => $promo->id]);
                }
            } else {
                $promo = (object) [
                    'id'                   => 0,
                    'name'                 => '',
                    'url'                  => '',
                    'visible'              => 1,
                    'feed_enabled'         => 0,
                    'has_date_range'       => 0,
                    'promo_type'           => PromoCampaignEntity::TYPE_PERCENT,
                    'priority'             => 0,
                    'min_order_amount'     => 0,
                    'discount_percent'     => null,
                    'discount_fixed'       => null,
                    'image_mobile'         => '',
                    'image_width'          => PromoCampaignEntity::DEFAULT_IMAGE_WIDTH,
                    'image_height'         => PromoCampaignEntity::DEFAULT_IMAGE_HEIGHT,
                    'image_mobile_width'   => PromoCampaignEntity::DEFAULT_IMAGE_MOBILE_WIDTH,
                    'image_mobile_height'  => PromoCampaignEntity::DEFAULT_IMAGE_MOBILE_HEIGHT,
                    'badge_image'          => '',
                    'caption_banner_image' => '',
                    'caption_banner_width' => PromoCampaignEntity::DEFAULT_CAPTION_BANNER_WIDTH,
                    'caption_banner_height' => PromoCampaignEntity::DEFAULT_CAPTION_BANNER_HEIGHT,
                    'product_caption_mode' => PromoCampaignEntity::PRODUCT_CAPTION_BELOW,
                ];
            }
        }

        // ── Подарунки ────────────────────────────────────────────────────────
        if (!empty($promoGifts)) {
            $p_gifts = [];
            foreach ($promoGifts as &$p_g) {
                $p_gifts[$p_g->gift_id] = &$p_g;
            }
            $imagesIds = [];
            foreach ($productsEntity->find(['id' => array_keys($p_gifts)]) as $product) {
                $p_gifts[$product->id] = $product;
                $imagesIds[] = $product->main_image_id;
            }
            foreach ($imagesEntity->find(['id' => $imagesIds]) as $image) {
                $p_gifts[$image->product_id]->images[] = $image;
            }
        }
        $this->design->assign('promo_gifts', $promoGifts);

        // ── Об'єкти області дії ───────────────────────────────────────────────
        $emptyScope   = ['category' => [], 'brand' => [], 'product' => [], 'feature_value' => []];
        $objects_array = ['include' => $emptyScope, 'exclude' => $emptyScope];

        if (is_array($promoObjects) && (isset($promoObjects['include']) || isset($promoObjects['exclude']))) {
            foreach (['include', 'exclude'] as $mode) {
                if (empty($promoObjects[$mode])) {
                    continue;
                }
                foreach (['category', 'brand'] as $type) {
                    $objects_array[$mode][$type] = array_map('intval', (array) ($promoObjects[$mode][$type] ?? []));
                }
                foreach ((array) ($promoObjects[$mode]['product'] ?? []) as $productId) {
                    $productId = (int) $productId;
                    if ($productId > 0) {
                        $objects_array[$mode]['product'][$productId] = $productId;
                    }
                }
            }
        } elseif (!empty($promoObjects)) {
            foreach ($promoObjects as $p_o) {
                if (!is_object($p_o) || !isset($p_o->type, $p_o->object_id)) {
                    continue;
                }
                $mode = !empty($p_o->exclude) ? 'exclude' : 'include';
                if ($p_o->type === 'product') {
                    $objects_array[$mode]['product'][$p_o->object_id] = $p_o->object_id;
                } elseif ($p_o->type === 'category') {
                    $objects_array[$mode]['category'][] = (int) $p_o->object_id;
                } elseif ($p_o->type === 'brand') {
                    $objects_array[$mode]['brand'][] = (int) $p_o->object_id;
                } elseif ($p_o->type === 'feature_value') {
                    $fid = (int) ($p_o->feature_id ?? 0);
                    if ($fid > 0) {
                        $objects_array[$mode]['feature_value'][$fid][] = (int) $p_o->object_id;
                    }
                }
            }
        }

        foreach (['include', 'exclude'] as $mode) {
            if (!empty($objects_array[$mode]['product'])) {
                $imagesIds = [];
                foreach ($productsEntity->find(['id' => $objects_array[$mode]['product']]) as $p) {
                    $objects_array[$mode]['product'][$p->id] = $p;
                    $imagesIds[] = $p->main_image_id;
                }
                foreach ($imagesEntity->find(['id' => $imagesIds]) as $image) {
                    $objects_array[$mode]['product'][$image->product_id]->images[] = $image;
                }
            }
        }

        // ── Доступні фіди (для перемикача фідів у кампанії) ──────────────────
        $availableFeeds = $this->loadAvailableFeeds($entityFactory);

        // ── Вже збережені прив'язки фідів для кампанії ───────────────────────
        $linkedFeedIds = !empty($promo->id)
            ? $campaignRepository->getLinkedFeedIds((int) $promo->id)
            : [];

        $this->design->assign('promo_objects', $objects_array);
        $this->design->assign('categories', $categoriesEntity->getCategoriesTree());
        $this->design->assign('brands', $brandsEntity->find());
        $this->design->assign('promo', $promo);
        $this->design->assign('date_start_local', !empty($promo->date_start) ? date('Y-m-d\TH:i', strtotime((string) $promo->date_start)) : date('Y-m-d\T00:00'));
        $this->design->assign('date_end_local',   !empty($promo->date_end)   ? date('Y-m-d\TH:i', strtotime((string) $promo->date_end))   : date('Y-m-d\T23:59'));
        $this->design->assign('available_feeds', $availableFeeds);
        $this->design->assign('linked_feed_ids', $linkedFeedIds);

        $featuresEntity       = $entityFactory->get(FeaturesEntity::class);
        $featuresValuesEntity = $entityFactory->get(FeaturesValuesEntity::class);
        $features             = $featuresEntity->find();
        $featureValuesMap     = [];
        $featureValuesJson    = [];

        $selectedFeatureIds = [];
        foreach (['include', 'exclude'] as $mode) {
            foreach (array_keys((array) ($objects_array[$mode]['feature_value'] ?? [])) as $featureId) {
                $featureId = (int) $featureId;
                if ($featureId > 0) {
                    $selectedFeatureIds[$featureId] = $featureId;
                }
            }
        }

        if (!empty($selectedFeatureIds)) {
            foreach ($featuresValuesEntity->find(['feature_id' => array_values($selectedFeatureIds)]) as $value) {
                $featureId = (int) $value->feature_id;
                if ($featureId <= 0) {
                    continue;
                }
                $featureValuesMap[$featureId][] = $value;
                $featureValuesJson[$featureId][] = [
                    'id'    => (int) $value->id,
                    'value' => (string) $value->value,
                ];
            }
        }

        $featuresJsonList = array_map(
            static fn($f) => ['id' => (int) $f->id, 'name' => (string) $f->name],
            $features
        );
        $this->design->assign('features', $features);
        $this->design->assign('feature_values_map', $featureValuesMap);
        $this->design->assign('sv_promo_fv_json', json_encode($featureValuesJson));
        $this->design->assign('sv_promo_feat_json', json_encode($featuresJsonList));
        $this->design->assign('promo_types', [
            PromoCampaignEntity::TYPE_PERCENT      => $backendTranslations->getTranslation('sviat_promo__type_percent'),
            PromoCampaignEntity::TYPE_FIXED        => $backendTranslations->getTranslation('sviat_promo__type_fixed'),
            PromoCampaignEntity::TYPE_GIFT         => $backendTranslations->getTranslation('sviat_promo__type_gift'),
            PromoCampaignEntity::TYPE_BUNDLE_3X2   => $backendTranslations->getTranslation('sviat_promo__type_bundle'),
            PromoCampaignEntity::TYPE_FREE_SHIPPING => $backendTranslations->getTranslation('sviat_promo__type_free_shipping'),
        ]);

        $this->response->setContent($this->design->fetch('campaign_edit.tpl'));
    }

    // -------------------------------------------------------------------------
    // Приватні допоміжні методи
    // -------------------------------------------------------------------------

    /**
     * Завантажує всі доступні фіди з OkayCMS/Feeds та OkayCMS/GoogleMerchant.
     * Перевіряє class_exists(), щоб контролер працював навіть без одного з модулів.
     *
     * @return array{feeds: object[], gm: object[]}
     */
    private function loadAvailableFeeds(EntityFactory $entityFactory): array
    {
        $result = ['feeds' => [], 'gm' => []];

        $feedsEntityClass = \Okay\Modules\OkayCMS\Feeds\Entities\FeedsEntity::class;
        if (class_exists($feedsEntityClass)) {
            $result['feeds'] = $entityFactory->get($feedsEntityClass)->find();
        }

        $gmEntityClass = \Okay\Modules\OkayCMS\GoogleMerchant\Entities\GoogleMerchantFeedsEntity::class;
        if (class_exists($gmEntityClass)) {
            $result['gm'] = $entityFactory->get($gmEntityClass)->find();
        }

        return $result;
    }

    private function normalizePromoObjects($raw): array
    {
        $emptyScope = ['category' => [], 'brand' => [], 'product' => [], 'feature_value' => []];
        $out        = ['include' => $emptyScope, 'exclude' => $emptyScope];
        if (!is_array($raw)) {
            return $out;
        }
        foreach (['include', 'exclude'] as $mode) {
            if (empty($raw[$mode]) || !is_array($raw[$mode])) {
                continue;
            }
            foreach (['category', 'brand', 'product'] as $type) {
                if (empty($raw[$mode][$type]) || !is_array($raw[$mode][$type])) {
                    continue;
                }
                foreach ($raw[$mode][$type] as $oid) {
                    if ($oid === '' || $oid === null) {
                        continue;
                    }
                    $out[$mode][$type][] = (int) $oid;
                }
            }
            // feature_value: nested [feature_id => [value_ids]]
            if (!empty($raw[$mode]['feature_value']) && is_array($raw[$mode]['feature_value'])) {
                foreach ($raw[$mode]['feature_value'] as $featureId => $valueIds) {
                    $featureId = (int) $featureId;
                    if ($featureId <= 0 || !is_array($valueIds)) {
                        continue;
                    }
                    foreach ($valueIds as $vid) {
                        if ($vid !== '' && $vid !== null) {
                            $out[$mode]['feature_value'][$featureId][] = (int) $vid;
                        }
                    }
                }
            }
        }

        return $out;
    }

    private function hasAnyObject(array $promoObjects): bool
    {
        $inc = $promoObjects['include'] ?? [];
        return !empty($inc['category'])
            || !empty($inc['brand'])
            || !empty($inc['product'])
            || !empty($inc['feature_value']);
    }

    private function getFeatureValuesForAjax(EntityFactory $entityFactory): array
    {
        $featureId = $this->request->get('feature_id', 'integer');
        if (empty($featureId)) {
            return [];
        }

        $featuresValuesEntity = $entityFactory->get(FeaturesValuesEntity::class);
        $items = [];
        foreach ($featuresValuesEntity->find(['feature_id' => (int) $featureId]) as $value) {
            $items[] = [
                'id'    => (int) $value->id,
                'value' => (string) $value->value,
            ];
        }

        return $items;
    }
}
