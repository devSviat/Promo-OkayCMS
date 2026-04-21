<?php

namespace Okay\Modules\Sviat\Promo\Controllers;

use Okay\Controllers\AbstractController;
use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoRewardLineEntity;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;

class GiftSelectionEndpoint extends AbstractController
{
    public function ajaxAddGiftToCart(Request $request, EntityFactory $entityFactory, PromotionEligibility $eligibility)
    {
        $gift = $request->post('gift_product', 'integer');
        $gift_variant = $request->post('gift_variant', 'integer');
        $product = $request->post('product', 'integer');
        $variant = $request->post('variant', 'integer');
        $promo_id = $request->post('promo_id', 'integer');

        if ($gift < 1 || $gift_variant < 1 || $product < 1 || $variant < 1 || $promo_id < 1) {
            return;
        }

        /** @var PromoCampaignEntity $campaigns */
        $campaigns = $entityFactory->get(PromoCampaignEntity::class);
        $campaign = $campaigns->get($promo_id);
        if (empty($campaign->id) || $campaign->promo_type !== PromoCampaignEntity::TYPE_GIFT) {
            return;
        }

        if (!$eligibility->campaignDatesOk($campaign)) {
            return;
        }

        /** @var PromoRewardLineEntity $rewardLines */
        $rewardLines = $entityFactory->get(PromoRewardLineEntity::class);
        $row = $rewardLines->findOne([
            'promo_id' => $promo_id,
            'gift_id' => $gift,
            'visible' => 1,
        ]);
        if (empty($row)) {
            return;
        }

        $_SESSION['shopping_sviat_promo_gift'][$variant] = [
            'gift_id' => $gift,
            'gift_variant_id' => $gift_variant,
            'product_id' => $product,
            'promo_id' => $promo_id,
        ];
    }
}
