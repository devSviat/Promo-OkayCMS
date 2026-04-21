<?php

namespace Okay\Modules\Sviat\Promo\Plugins;

use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\SmartyPlugins\Func;
use Okay\Helpers\ProductsHelper;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoRewardLineEntity;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;

/**
 * Мітка «подарунок» у списку товарів, якщо для SKU є gift-кампанія (найвищий пріоритет серед gift).
 */
class GiftBadgePlugin extends Func
{
    protected $tag = 'sviat_promo_icon';

    protected $design;
    protected $entityFactory;
    protected $productsHelper;
    protected $promotionEligibility;

    public function __construct(
        Design $design,
        EntityFactory $entityFactory,
        ProductsHelper $productsHelper,
        PromotionEligibility $promotionEligibility
    ) {
        $this->design = $design;
        $this->entityFactory = $entityFactory;
        $this->productsHelper = $productsHelper;
        $this->promotionEligibility = $promotionEligibility;
    }

    public function run($vars)
    {
        $promoIds = $this->promotionEligibility->promoIdsForProduct($vars['product']);

        if ($promoIds === []) {
            return false;
        }

        $campaign = $this->promotionEligibility->pickBestActiveCampaign($promoIds, [PromoCampaignEntity::TYPE_GIFT]);
        if ($campaign === null) {
            return false;
        }

        $rewardLines = $this->entityFactory->get(PromoRewardLineEntity::class);
        $promoGift = $rewardLines->findOne(['promo_id' => $campaign->id, 'visible' => 1]);
        if (empty($promoGift)) {
            return false;
        }

        $gift = $this->productsHelper->getList(['id' => $promoGift->gift_id]);
        $this->design->assign('gift', $gift);
        $this->design->assign('promo', $campaign);

        return $this->design->fetch('promo_icon.tpl');
    }
}
