<?php

namespace Okay\Modules\Sviat\Promo\Plugins;

use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\SmartyPlugins\Func;
use Okay\Entities\CurrenciesEntity;
use Okay\Helpers\ProductsHelper;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoRewardLineEntity;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;

/**
 * Блок на картці товару: активна кампанія за областю дії (найвищий пріоритет) або подарунок.
 */
class ProductCampaignBlockPlugin extends Func
{
    protected $tag = 'sviat_promo_product';

    protected $design;
    protected $entityFactory;
    protected $productsHelper;
    protected $promotionEligibility;
    protected $currencyPrecision;

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
        /** @var CurrenciesEntity $currenciesEntity */
        $currenciesEntity = $entityFactory->get(CurrenciesEntity::class);
        $mainCurrency = $currenciesEntity->getMainCurrency();
        $this->currencyPrecision = max(0, (int) ($mainCurrency->cents ?? 2));
    }

    public function run($vars)
    {
        if (empty($vars['product']) || empty($vars['product']->variant)) {
            return false;
        }

        $promoIds = $this->promotionEligibility->promoIdsForProduct($vars['product']);

        if ($promoIds === []) {
            return false;
        }

        $campaign = $this->promotionEligibility->pickBestActiveCampaign($promoIds);
        if ($campaign === null) {
            return false;
        }

        // Для fixed-акції блок показуємо лише коли ціна поточного варіанта не менша за discount_fixed.
        if ($campaign->promo_type === PromoCampaignEntity::TYPE_FIXED) {
            $fixed = (float) ($campaign->discount_fixed ?? 0);
            $base = (float) ($vars['product']->variant->price ?? 0);
            if ($fixed <= 0 || $base < $fixed) {
                return false;
            }
        }

        return $this->renderCampaignBlock($campaign, $vars['product']);
    }

    /**
     * @param object $product
     */
    private function renderCampaignBlock(object $campaign, $product): string
    {
        $rewardLines = $this->entityFactory->get(PromoRewardLineEntity::class);

        if ($campaign->promo_type === PromoCampaignEntity::TYPE_GIFT) {
            $promoGifts = $rewardLines->find(['promo_id' => $campaign->id]);

            $productIds = [];
            if (!empty($promoGifts)) {
                foreach ($promoGifts as $promoGift) {
                    $productIds[] = $promoGift->gift_id;
                }
            }

            if ($campaign->has_date_range) {
                $campaign->seconds_left = (int) strtotime((string) $campaign->date_end) - time();
            }
            if (!empty($productIds)) {
                $campaign->gifts = $this->productsHelper->getList(['id' => $productIds]);
            }
        } else {
            $campaign->gifts = null;
            if ($campaign->has_date_range) {
                $campaign->seconds_left = (int) strtotime((string) $campaign->date_end) - time();
            }
        }

        // Якщо PromoProductDisplayService вже обчислив ціну зі знижкою (через getList/attachProductData),
        // читаємо готові значення. Інакше обчислюємо вручну.
        // Важливо: НЕ перераховувати знижку від вже-знижненої ціни (подвійний баг).
        if (!empty($product->sviat_promo_price_display_applied)) {
            $campaign->sviat_promo_effective_price = $product->sviat_promo_discounted_unit_price ?? null;
        } else {
            $this->computeEffectivePrice($campaign, $product);
        }

        $this->design->assign('product', $product);
        $this->design->assign('promo', $campaign);

        return $this->design->fetch('promo_product.tpl');
    }

    /**
     * Обчислює ціну після знижки та встановлює поля на $campaign і $product.
     * Викликається лише якщо PromoProductDisplayService ще не оброблював цей товар.
     *
     * @param object $product
     */
    private function computeEffectivePrice(object $campaign, $product): void
    {
        if (empty($product->variant)) {
            return;
        }
        $base = (float) $product->variant->price;
        if ($base <= 0) {
            return;
        }

        if ($campaign->promo_type === PromoCampaignEntity::TYPE_PERCENT) {
            $pct = (float) ($campaign->discount_percent ?? 0);
            if ($pct > 0 && $pct <= 100) {
                $effective = round($base * (100 - $pct) / 100, $this->currencyPrecision);
                $campaign->sviat_promo_effective_price = $effective;
                $product->sviat_promo_discounted_unit_price = $effective;
                $product->sviat_promo_discount_percent = $pct;
            }
        } elseif ($campaign->promo_type === PromoCampaignEntity::TYPE_FIXED) {
            $fixed = (float) ($campaign->discount_fixed ?? 0);
            if ($fixed > 0 && $base >= $fixed) {
                $effective = round($base - $fixed, $this->currencyPrecision);
                $campaign->sviat_promo_effective_price = $effective;
                $product->sviat_promo_discounted_unit_price = $effective;
                $product->sviat_promo_discount_percent = round(($fixed / $base) * 100, 2);
            }
        }
    }
}
