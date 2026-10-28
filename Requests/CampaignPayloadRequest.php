<?php

namespace Okay\Modules\Sviat\Promo\Requests;

use Okay\Core\Request;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;

class CampaignPayloadRequest
{
    /** @var Request */
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function postPromo(): \stdClass
    {
        $promo = new \stdClass();
        $promo->id      = $this->request->post('id', 'integer');
        $promo->name    = $this->request->post('name');
        $rawVisible      = $this->request->post('visible');
        if (is_array($rawVisible)) {
            $rawVisible = end($rawVisible);
        }
        $promo->visible = !empty($rawVisible) ? 1 : 0;
        $promo->url     = trim((string) $this->request->post('url', 'string'));
        $promo->meta_title = $this->request->post('meta_title');
        $promo->meta_keywords = $this->request->post('meta_keywords');
        $promo->meta_description = $this->request->post('meta_description');
        $promo->annotation = $this->request->post('annotation');
        $promo->description = $this->request->post('description');

        $promo->has_date_range = $this->request->post('has_date_range', 'integer');
        $promo->date_start     = null;
        $promo->date_end       = null;
        if ($promo->has_date_range) {
            $dateStartRaw = (string) $this->request->post('date_start', 'string');
            $dateEndRaw   = (string) $this->request->post('date_end', 'string');

            if ($dateStartRaw) {
                $ts = strtotime($dateStartRaw);
                if ($ts !== false) {
                    $promo->date_start = date('Y-m-d H:i:00', $ts);
                }
            }
            if ($dateEndRaw) {
                $ts = strtotime($dateEndRaw);
                if ($ts !== false) {
                    $promo->date_end = date('Y-m-d H:i:00', $ts);
                }
            }
        }

        $type = $this->request->post('promo_type', 'string');
        $allowed = [
            PromoCampaignEntity::TYPE_PERCENT,
            PromoCampaignEntity::TYPE_QUANTITY,
            PromoCampaignEntity::TYPE_FIXED,
            PromoCampaignEntity::TYPE_GIFT,
            PromoCampaignEntity::TYPE_BUNDLE_3X2,
            PromoCampaignEntity::TYPE_FREE_SHIPPING,
        ];
        $promo->promo_type = in_array($type, $allowed, true) ? $type : PromoCampaignEntity::TYPE_GIFT;

        $promo->min_order_amount = (float) str_replace(',', '.', (string) $this->request->post('min_order_amount', 'string'));

        // У старих акціях перемикач вимкнено. Параметр діє лише для цінових акцій.
        $roundFinalPrice = $this->request->post('round_final_price');
        if (is_array($roundFinalPrice)) {
            $roundFinalPrice = end($roundFinalPrice);
        }
        $promo->round_final_price = in_array($promo->promo_type, [
            PromoCampaignEntity::TYPE_PERCENT,
            PromoCampaignEntity::TYPE_FIXED,
            PromoCampaignEntity::TYPE_QUANTITY,
        ], true) && (string) $roundFinalPrice === '1' ? 1 : 0;

        $promo->discount_percent = null;
        $promo->discount_fixed = null;
        if ($promo->promo_type === PromoCampaignEntity::TYPE_PERCENT) {
            $rawDiscountPercent = trim((string) $this->request->post('discount_percent', 'string'));
            if ($rawDiscountPercent !== '') {
                $discountPercent = (float) str_replace(',', '.', $rawDiscountPercent);
                if ($discountPercent < 1) {
                    $discountPercent = 1.0;
                } elseif ($discountPercent > 100) {
                    $discountPercent = 100.0;
                }
                $promo->discount_percent = $discountPercent;
            }
        } elseif ($promo->promo_type === PromoCampaignEntity::TYPE_FIXED) {
            $rawDiscountFixed = trim((string) $this->request->post('discount_fixed', 'string'));
            if ($rawDiscountFixed !== '' && preg_match('/^\d+(?:[.,]\d+)?$/', $rawDiscountFixed)) {
                $promo->discount_fixed = (float) str_replace(',', '.', $rawDiscountFixed);
            }
        }

        $mode = (int) $this->request->post('product_caption_mode', 'integer');
        $allowedModes = [
            PromoCampaignEntity::PRODUCT_CAPTION_BELOW,
            PromoCampaignEntity::PRODUCT_CAPTION_REPLACE,
            PromoCampaignEntity::PRODUCT_CAPTION_ABOVE,
            PromoCampaignEntity::PRODUCT_CAPTION_IMAGE_ONLY,
        ];
        $promo->product_caption_mode = in_array($mode, $allowedModes, true)
            ? $mode
            : PromoCampaignEntity::PRODUCT_CAPTION_BELOW;

        $promo->feed_enabled = $this->request->post('feed_enabled', 'boolean') ? 1 : 0;
        $promo->exclude_no_image = (int) $this->request->post('exclude_no_image', 'integer');
        $promo->image_width = $this->normalizePositiveInt(
            $this->request->post('image_width', 'integer'),
            PromoCampaignEntity::DEFAULT_IMAGE_WIDTH
        );
        $promo->image_height = $this->normalizePositiveInt(
            $this->request->post('image_height', 'integer'),
            PromoCampaignEntity::DEFAULT_IMAGE_HEIGHT
        );
        $promo->image_mobile_width = $this->normalizePositiveInt(
            $this->request->post('image_mobile_width', 'integer'),
            PromoCampaignEntity::DEFAULT_IMAGE_MOBILE_WIDTH
        );
        $promo->image_mobile_height = $this->normalizePositiveInt(
            $this->request->post('image_mobile_height', 'integer'),
            PromoCampaignEntity::DEFAULT_IMAGE_MOBILE_HEIGHT
        );
        $promo->catalog_image_width = $this->normalizePositiveInt(
            $this->request->post('catalog_image_width', 'integer'),
            PromoCampaignEntity::DEFAULT_CATALOG_IMAGE_WIDTH
        );
        $promo->catalog_image_height = $this->normalizePositiveInt(
            $this->request->post('catalog_image_height', 'integer'),
            PromoCampaignEntity::DEFAULT_CATALOG_IMAGE_HEIGHT
        );
        $promo->caption_banner_width = $this->normalizePositiveInt(
            $this->request->post('caption_banner_width', 'integer'),
            PromoCampaignEntity::DEFAULT_CAPTION_BANNER_WIDTH
        );
        $promo->caption_banner_height = $this->normalizePositiveInt(
            $this->request->post('caption_banner_height', 'integer'),
            PromoCampaignEntity::DEFAULT_CAPTION_BANNER_HEIGHT
        );

        return $promo;
    }

    /**
     * Пороги акції «за кількістю», відсортовані за кількістю.
     * Будь-який некоректний рядок обнуляє весь набір: часткова шкала мовчки дала б іншу знижку.
     *
     * @return array<int, array{min_quantity: int, percent: float}>
     */
    public function postQuantityTiers(): array
    {
        $raw = $this->request->post('quantity_tiers');
        if (!is_array($raw)) {
            return [];
        }
        $tiers = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $quantityRaw = trim((string) ($row['min_quantity'] ?? ''));
            $percentRaw = str_replace(',', '.', trim((string) ($row['percent'] ?? '')));
            if (!preg_match('/^[0-9]+$/D', $quantityRaw) ||
                !preg_match('/^(?:[0-9]+)(?:\.[0-9]{1,2})?$/D', $percentRaw)) {
                return [];
            }
            $quantity = (int) $quantityRaw;
            $percent = (float) $percentRaw;
            if ($quantity < 2 || $quantity > 100000 || $percent <= 0 || $percent > 100 || isset($tiers[$quantity])) {
                return [];
            }
            $tiers[$quantity] = ['min_quantity' => $quantity, 'percent' => $percent];
        }
        ksort($tiers, SORT_NUMERIC);
        return array_values($tiers);
    }

    /**
     * Повертає вибрані фіди, згруповані за типом.
     * Приклад: ['feeds' => [1, 2], 'gm' => [3]].
     *
     * @return array<string, int[]>
     */
    public function postFeedIds(): array
    {
        $raw = $this->request->post('feed_ids', null, []);
        if (!is_array($raw)) {
            return [];
        }
        $result = [];
        foreach (['feeds', 'gm'] as $type) {
            if (!empty($raw[$type]) && is_array($raw[$type])) {
                $result[$type] = array_values(array_map(intval(...), $raw[$type]));
            }
        }
        return $result;
    }

    public function postPromoGift($promo): ?array
    {
        if (is_array($this->request->post('promo_gifts'))) {
            $p_g = [];
            foreach ($this->request->post('promo_gifts') as $g) {
                $p_g[$g] = new \stdClass();
                $p_g[$g]->promo_id = $promo->id;
                $p_g[$g]->gift_id = $g;
            }

            return $p_g;
        }

        return null;
    }

    public function postPromoObject()
    {
        return $this->request->post('promo_objects');
    }

    public function postDeleteImage()
    {
        return $this->request->post('delete_image');
    }

    public function postDeleteBadgeImage()
    {
        return $this->request->post('delete_badge_image');
    }

    public function postDeleteMobileImage()
    {
        return $this->request->post('delete_image_mobile');
    }

    public function postDeleteCatalogImage()
    {
        return $this->request->post('delete_catalog_image');
    }

    public function postDeleteCaptionBannerImage()
    {
        return $this->request->post('delete_caption_banner_image');
    }

    public function fileImage()
    {
        return $this->request->files('image');
    }

    public function fileBadgeImage()
    {
        return $this->request->files('badge_image');
    }

    public function fileMobileImage()
    {
        return $this->request->files('image_mobile');
    }

    public function fileCatalogImage()
    {
        return $this->request->files('catalog_image');
    }

    public function fileCaptionBannerImage()
    {
        return $this->request->files('caption_banner_image');
    }

    private function normalizePositiveInt($value, int $default): int
    {
        $value = (int) $value;
        return $value > 0 ? $value : $default;
    }


}
