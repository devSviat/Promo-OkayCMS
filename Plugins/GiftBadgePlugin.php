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

    /** @var array<int, object|null> promoId => рядок подарунка */
    private $rewardLineCache = [];

    /** @var array<int, array> giftId => результат getList */
    private $giftProductCache = [];

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

        $promoGift = $this->rewardLineForCampaign((int) $campaign->id);
        if (empty($promoGift)) {
            return false;
        }

        $this->design->assign('gift', $this->giftProducts((int) $promoGift->gift_id));
        $this->design->assign('promo', $campaign);

        return $this->design->fetch('promo_icon.tpl');
    }

    /**
     * Плагін виконується на кожну картку, а подарунок у кампанії один — без
     * мемоїзації це був окремий SELECT на товар.
     */
    private function rewardLineForCampaign(int $campaignId)
    {
        if (!array_key_exists($campaignId, $this->rewardLineCache)) {
            $rewardLines = $this->entityFactory->get(PromoRewardLineEntity::class);
            $this->rewardLineCache[$campaignId] = $rewardLines->findOne(['promo_id' => $campaignId, 'visible' => 1]);
        }

        return $this->rewardLineCache[$campaignId];
    }

    /**
     * getList() тягне за собою варіанти, головну картинку і власний
     * Promo-декоратор — тобто цілий конвеєр товару всередині рендеру картки.
     */
    private function giftProducts(int $giftId): array
    {
        if (!isset($this->giftProductCache[$giftId])) {
            $this->giftProductCache[$giftId] = $this->productsHelper->getList(['id' => $giftId]);
        }

        return $this->giftProductCache[$giftId];
    }
}
