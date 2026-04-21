<?php

namespace Okay\Modules\Sviat\Promo\Entities;

use Okay\Core\Entity\Entity;

class PromoCampaignEntity extends Entity
{
    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';
    public const TYPE_GIFT = 'gift';
    public const TYPE_BUNDLE_3X2 = 'bundle_3x2';
    public const TYPE_FREE_SHIPPING = 'free_shipping';

    /** Під текстом акції: рядок + таймер, потім банер */
    public const PRODUCT_CAPTION_BELOW = 0;

    /** Замість рядка з назвою: банер, посилання «Детальніше», таймер */
    public const PRODUCT_CAPTION_REPLACE = 1;

    /** Над текстом: банер, потім рядок акції та таймер */
    public const PRODUCT_CAPTION_ABOVE = 2;

    /** Лише зображення банера (без тексту та таймера) */
    public const PRODUCT_CAPTION_IMAGE_ONLY = 3;

    protected static $fields = [
        'id',
        'name',
        'url',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'annotation',
        'description',
        'has_date_range',
        'date_start',
        'date_end',
        'image',
        'image_mobile',
        'badge_image',
        'caption_banner_image',
        'product_caption_mode',
        'visible',
        'feed_enabled',
        'last_modify',
        'promo_type',
        'priority',
        'position',
        'min_order_amount',
        'discount_percent',
        'discount_fixed',
    ];

    protected static $additionalFields = [
        'TIMESTAMPDIFF(DAY, now(), date_end) as days_left',
    ];

    protected static $langFields = [
        'name',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'annotation',
        'description',
    ];

    protected static $searchFields = [
        'name',
        'meta_keywords',
    ];

    protected static $defaultOrderFields = [
        'position',
        'id',
    ];

    protected static $table = 'sviat__promos';
    protected static $langObject = 'sviat_promos';
    protected static $langTable = 'sviat__promos';
    protected static $tableAlias = 'sp';

    public function find(array $filter = [])
    {
        if (isset($filter['past_promos'])) {
            $this->select->where('sp.has_date_range = 1 AND sp.date_end < NOW()');
        } elseif (isset($filter['cart_active'])) {
            $this->select->where('(sp.has_date_range = 0 OR (sp.has_date_range = 1 AND sp.date_start <= NOW() AND sp.date_end >= NOW()))');
        } elseif (isset($filter['current_promos'])) {
            $this->select->where('((sp.has_date_range = 1 AND sp.date_end >= NOW()) OR sp.has_date_range = 0)');
        } elseif (isset($filter['future_promos'])) {
            $this->select->where('sp.has_date_range = 1 AND sp.date_start > NOW()');
        } elseif (isset($filter['front_promos'])) {
            $this->select->where('((sp.has_date_range = 1 AND sp.date_start <= NOW()) OR sp.has_date_range = 0)');
        }

        if (empty($filter['admin_list'])) {
            $this->select->where('sp.visible = 1');
        }

        if (isset($filter['cart_promos'])) {
            $this->select->orderBy(['sp.position ASC', 'sp.id ASC']);
        }

        return parent::find($filter);
    }

}
