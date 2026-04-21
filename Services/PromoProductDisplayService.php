<?php

namespace Okay\Modules\Sviat\Promo\Services;

use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Psr\Log\LoggerInterface;

/**
 * Підганяє variant->price / compare_price під шаблони теми (стікер % у product_list.tpl, блок цін у product.tpl).
 */
class PromoProductDisplayService
{
    /** @var PromotionEligibility */
    private $eligibility;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(PromotionEligibility $eligibility, LoggerInterface $logger)
    {
        $this->eligibility = $eligibility;
        $this->logger = $logger;
    }

    /**
     * Для списків / картки: знайти кампанію й виставити compare_price > price як у стандартному розпродажі.
     * Один запит до БД замість двох.
     */
    public function decorateProduct(object $product): void
    {
        if (empty($product->id) || empty($product->variant)) {
            return;
        }
        if (!empty($product->sviat_promo_price_display_applied)) {
            return;
        }

        $ids = $this->eligibility->promoIdsForProduct($product);
        if ($ids === []) {
            return;
        }

        $allActive = $this->eligibility->getActiveCampaigns($ids);
        if ($allActive === []) {
            return;
        }

        // Бейдж беремо з найпріоритетнішої кампанії будь-якого типу
        if (!empty($allActive[0]->badge_image)) {
            $product->sviat_promo_badge_image = $allActive[0]->badge_image;
        }

        // Для відображення беремо першу відсоткову/фіксовану акцію у вже відсортованому списку
        $discountCampaign = null;
        foreach ($allActive as $c) {
            $type = strtolower(trim((string) ($c->promo_type ?? '')));
            if ($type === PromoCampaignEntity::TYPE_PERCENT || $type === PromoCampaignEntity::TYPE_FIXED) {
                $discountCampaign = $c;
                break;
            }
        }
        $this->applyDiscountDisplay($product, $discountCampaign);
    }

    public function applyDiscountDisplay(object $product, ?object $campaign): void
    {
        $pid = (int) ($product->id ?? 0);
        if ($campaign === null) {
            return;
        }

        if (empty($product->variant)) {
            return;
        }

        $base = (float) $product->variant->price;
        if ($base <= 0) {
            $this->logger->warning('Sviat.Promo display: variant price <= 0', ['product_id' => $pid]);

            return;
        }

        $type = strtolower(trim((string) ($campaign->promo_type ?? '')));
        if ($type === PromoCampaignEntity::TYPE_PERCENT) {
            $pct = (float) ($campaign->discount_percent ?? 0);
            if ($pct <= 0 || $pct > 100) {
                $this->logger->warning('Sviat.Promo display: invalid discount_percent', [
                    'product_id' => $pid,
                    'promo_id' => (int) ($campaign->id ?? 0),
                    'discount_percent' => $pct,
                ]);

                return;
            }
            $effective = round($base * (100 - $pct) / 100, 4);
            $product->sviat_promo_discount_percent = $pct;
        } elseif ($type === PromoCampaignEntity::TYPE_FIXED) {
            $fixed = (float) ($campaign->discount_fixed ?? 0);
            if ($fixed <= 0 || $base < $fixed) {
                return;
            }
            $effective = round($base - $fixed, 4);
            $product->sviat_promo_discount_percent = round(($fixed / $base) * 100, 2);
        } else {
            return;
        }

        $product->variant->compare_price = $base;
        $product->variant->price = $effective;
        $product->sviat_promo_discounted_unit_price = $effective;
        $product->sviat_promo_price_display_applied = true;
    }
}
