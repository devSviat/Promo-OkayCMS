<?php

namespace Okay\Modules\Sviat\Promo\Helpers;

use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Image;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoFeedLinkEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoRewardLineEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoScopeEntity;

/**
 * Доступ до кампаній та пов'язаних сутностей (область дії, подарункові SKU, feed-лінки).
 */
class CampaignRepository
{
    /** @var PromoCampaignEntity */
    private $campaigns;

    /** @var PromoRewardLineEntity */
    private $rewardLines;

    /** @var PromoScopeEntity */
    private $scope;

    /** @var PromoFeedLinkEntity */
    private $feedLinks;

    /** @var Image */
    private $imageCore;

    /** @var Config */
    private $config;

    public function __construct(
        EntityFactory $entityFactory,
        Config        $config,
        Image         $imageCore
    ) {
        $this->campaigns   = $entityFactory->get(PromoCampaignEntity::class);
        $this->rewardLines = $entityFactory->get(PromoRewardLineEntity::class);
        $this->scope       = $entityFactory->get(PromoScopeEntity::class);
        $this->feedLinks   = $entityFactory->get(PromoFeedLinkEntity::class);
        $this->config      = $config;
        $this->imageCore   = $imageCore;
    }

    public function add($promo)
    {
        return $this->campaigns->add($promo);
    }

    public function update($id, $promo): void
    {
        $this->campaigns->update($id, $promo);
    }

    public function getPromo($id)
    {
        return $this->campaigns->get($id);
    }

    public function findPromos($filter)
    {
        return $this->campaigns->find($filter);
    }

    // -------------------------------------------------------------------------
    // Прив'язки до фідів
    // -------------------------------------------------------------------------

    /**
     * Повертає ID фідів, прив'язаних до кампанії, згруповані за типом.
     *
     * @return array<string, int[]> Наприклад: ['feeds' => [1, 3], 'gm' => [2]]
     */
    public function getLinkedFeedIds(int $promoId): array
    {
        return $this->feedLinks->getLinkedFeedIds($promoId);
    }

    /**
     * Зберігає прив'язки фідів для кампанії.
     * Спочатку видаляє старі, потім записує нові.
     *
     * @param array<string, int[]> $feedIds Наприклад: ['feeds' => [1], 'gm' => [2, 3]]
     */
    public function saveFeedLinks(int $promoId, int $feedEnabled, array $feedIds): void
    {
        $this->feedLinks->deleteByPromoId($promoId);

        if (!$feedEnabled) {
            return;
        }

        foreach ([PromoFeedLinkEntity::TYPE_FEEDS, PromoFeedLinkEntity::TYPE_GM] as $type) {
            foreach ((array) ($feedIds[$type] ?? []) as $feedId) {
                $feedId = (int) $feedId;
                if ($feedId > 0) {
                    $this->feedLinks->add([
                        'promo_id'  => $promoId,
                        'feed_type' => $type,
                        'feed_id'   => $feedId,
                    ]);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Зображення
    // -------------------------------------------------------------------------

    private const PROMO_IMAGE_FIELDS = ['image', 'image_mobile', 'badge_image', 'caption_banner_image'];

    public function deleteImage($promo)
    {
        $this->deletePromoImageField('image', $promo);
        return null;
    }

    public function deletePromoImageField(string $field, $promo): void
    {
        if (!in_array($field, self::PROMO_IMAGE_FIELDS, true)) {
            return;
        }
        $this->imageCore->deleteImage(
            $promo->id,
            $field,
            PromoCampaignEntity::class,
            $this->config->get('promo_images_dir'),
            $this->config->get('resized_promo_images_dir')
        );
    }

    public function uploadImage($image, $promo, $isNew = false)
    {
        $this->uploadPromoImageField('image', $image, $promo, $isNew);
        return null;
    }

    public function uploadPromoImageField(string $field, $image, $promo, $isNew = false): void
    {
        if (!in_array($field, self::PROMO_IMAGE_FIELDS, true)) {
            return;
        }
        if (!empty($image['name']) && ($filename = $this->imageCore->uploadImage($image['tmp_name'], $image['name'], $this->config->get('promo_images_dir')))) {
            $this->imageCore->deleteImage(
                $promo->id,
                $field,
                PromoCampaignEntity::class,
                $this->config->get('promo_images_dir'),
                $this->config->get('resized_promo_images_dir')
            );
            $this->campaigns->update($promo->id, [$field => $filename]);
        }
    }

    // -------------------------------------------------------------------------
    // Видимість / масові дії
    // -------------------------------------------------------------------------

    public function enable($ids)
    {
        $this->campaigns->update($ids, ['visible' => 1]);
    }

    public function disable($ids)
    {
        $this->campaigns->update($ids, ['visible' => 0]);
    }

    public function delete($ids)
    {
        foreach ((array) $ids as $id) {
            $this->deleteObject($id);
            $this->deleteGift($id);
            $this->feedLinks->deleteByPromoId((int) $id);
            $p = $this->campaigns->get($id);
            if (!empty($p->id)) {
                foreach (self::PROMO_IMAGE_FIELDS as $field) {
                    if (!empty($p->$field)) {
                        $this->deletePromoImageField($field, $p);
                    }
                }
            }
        }
        $this->campaigns->delete($ids);
    }

    public function deleteObject($promoId)
    {
        return $this->scope->deleteByPromoId($promoId);
    }

    public function deleteGift($promoId)
    {
        return $this->rewardLines->removeGiftsByPromo($promoId);
    }

    public function countPromos($filter)
    {
        return $this->campaigns->count($filter);
    }
}
