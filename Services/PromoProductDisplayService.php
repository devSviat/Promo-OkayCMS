<?php

namespace Okay\Modules\Sviat\Promo\Services;

use Okay\Core\EntityFactory;
use Okay\Entities\CurrenciesEntity;
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

    /** @var int */
    private $currencyPrecision;

    public function __construct(PromotionEligibility $eligibility, LoggerInterface $logger, EntityFactory $entityFactory)
    {
        $this->eligibility = $eligibility;
        $this->logger = $logger;
        /** @var CurrenciesEntity $currenciesEntity */
        $currenciesEntity = $entityFactory->get(CurrenciesEntity::class);
        $mainCurrency = $currenciesEntity->getMainCurrency();
        $this->currencyPrecision = max(0, (int) ($mainCurrency->cents ?? 2));
    }

    /**
     * Для списків / картки: знайти кампанію й виставити compare_price > price як у стандартному розпродажі.
     * Кампанію обирають для кожного варіанта окремо, бо поріг «Ціна товару від N» залежить від його ціни.
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

        $priceCampaigns = [];
        foreach ($allActive as $c) {
            $type = strtolower(trim((string) ($c->promo_type ?? '')));
            // Ціну зі знижкою не можна гарантувати без складу кошика, якщо заданий мінімум замовлення.
            if ((float) ($c->min_order_amount ?? 0) > 0) {
                continue;
            }
            if ($type === PromoCampaignEntity::TYPE_PERCENT || $type === PromoCampaignEntity::TYPE_FIXED) {
                $priceCampaigns[] = $c;
            }
        }
        if ($priceCampaigns === []) {
            return;
        }

        foreach ($this->variantsOf($product) as $variant) {
            $campaign = $this->campaignForVariant($priceCampaigns, $variant);
            if ($campaign === null) {
                continue;
            }
            if ($variant === $product->variant) {
                $this->applyDiscountDisplay($product, $campaign);
            } else {
                $this->applyToVariant((int) $product->id, $variant, $campaign);
            }
        }
    }

    public function applyDiscountDisplay(object $product, ?object $campaign): void
    {
        if ($campaign === null || (float) ($campaign->min_order_amount ?? 0) > 0) {
            return;
        }

        if (empty($product->variant)) {
            return;
        }

        $percent = $this->applyToVariant((int) ($product->id ?? 0), $product->variant, $campaign);
        if ($percent === null) {
            return;
        }

        $product->sviat_promo_discount_percent = $percent;
        $product->sviat_promo_discounted_unit_price = $product->variant->price;
        $product->sviat_promo_price_display_applied = true;
    }

    /**
     * Перша за пріоритетом цінова кампанія, чий поріг ціни проходить варіант.
     *
     * @param object[] $priceCampaigns
     */
    private function campaignForVariant(array $priceCampaigns, object $variant): ?object
    {
        foreach ($priceCampaigns as $campaign) {
            if (PromotionEligibility::campaignAppliesToPrice($campaign, (float) ($variant->price ?? 0))) {
                return $campaign;
            }
        }

        return null;
    }

    /**
     * $product->variant зазвичай той самий об'єкт, що й перший у $product->variants,
     * але кеш чи інший модуль можуть дати копію: кожен об'єкт обробляємо один раз.
     *
     * @return object[]
     */
    private function variantsOf(object $product): array
    {
        $variants = [];
        foreach ([$product->variant, ...array_values(is_array($product->variants ?? null) ? $product->variants : [])] as $variant) {
            if (is_object($variant)) {
                $variants[spl_object_id($variant)] = $variant;
            }
        }

        return array_values($variants);
    }

    /**
     * Ставить варіанту ціну зі знижкою, а звичайну переносить у compare_price.
     * Звичайна ціна лишається в sviat_promo_base_price: за нею повторний виклик
     * для того самого об'єкта не знижує ціну вдруге, а блок акції бере базу для порогу.
     *
     * @return float|null відсоток знижки або null, якщо ціну не змінено
     */
    private function applyToVariant(int $productId, object $variant, object $campaign): ?float
    {
        if (isset($variant->sviat_promo_base_price)) {
            return null;
        }

        $base = (float) $variant->price;
        if ($base <= 0) {
            $this->logger->warning('Sviat.Promo display: variant price <= 0', ['product_id' => $productId]);

            return null;
        }

        $type = strtolower(trim((string) ($campaign->promo_type ?? '')));
        if ($type === PromoCampaignEntity::TYPE_PERCENT) {
            $pct = (float) ($campaign->discount_percent ?? 0);
            if ($pct <= 0 || $pct > 100) {
                $this->logger->warning('Sviat.Promo display: invalid discount_percent', [
                    'product_id' => $productId,
                    'promo_id' => (int) ($campaign->id ?? 0),
                    'discount_percent' => $pct,
                ]);

                return null;
            }
            $effective = PromoPriceRounding::price($campaign, $base * (100 - $pct) / 100, $this->currencyPrecision, $base);
        } elseif ($type === PromoCampaignEntity::TYPE_FIXED) {
            $fixed = (float) ($campaign->discount_fixed ?? 0);
            if ($fixed <= 0 || $base < $fixed) {
                return null;
            }
            $effective = PromoPriceRounding::price($campaign, $base - $fixed, $this->currencyPrecision, $base);
            $pct = round(($fixed / $base) * 100, 2);
        } else {
            return null;
        }

        $variant->sviat_promo_base_price = $base;
        $variant->compare_price = $base;
        $variant->price = $effective;

        return $pct;
    }
}
