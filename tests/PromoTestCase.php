<?php

namespace Modules\Sviat\Promo;

use Okay\Core\Classes\Discount;
use Okay\Core\Classes\Purchase;
use Okay\Core\EntityFactory;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use PHPUnit\Framework\TestCase;

abstract class PromoTestCase extends TestCase
{
    /**
     * Сток ще на PHP 8.0, де без setAccessible() рефлексія не пускає до
     * private, а форк уже на 8.5, де сам виклик застарілий і валить прогін.
     *
     * @param \ReflectionProperty|\ReflectionMethod $reflected
     * @return \ReflectionProperty|\ReflectionMethod
     */
    protected static function accessible($reflected)
    {
        if (PHP_VERSION_ID < 80100) {
            $reflected->setAccessible(true);
        }

        return $reflected;
    }

    /**
     * Builds an EntityFactory mock that maps class FQCN -> mock instance.
     *
     * @param array<class-string, object> $entityMocks
     */
    protected function mockEntityFactory(array $entityMocks): EntityFactory
    {
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(function (string $class) use ($entityMocks) {
            return $entityMocks[$class] ?? null;
        });
        return $factory;
    }

    /**
     * Mocks CurrenciesEntity whose getMainCurrency() returns a stdClass with the given cents.
     */
    protected function mockCurrenciesEntity(int $cents = 2): CurrenciesEntity
    {
        $currencies = $this->createStub(CurrenciesEntity::class);
        $currencies->method('getMainCurrency')->willReturn((object) ['id' => 1, 'cents' => $cents, 'rate_from' => 1, 'rate_to' => 1]);
        return $currencies;
    }

    /**
     * Builds a stdClass campaign with sensible defaults.
     */
    protected function buildPromo(array $overrides = []): \stdClass
    {
        return (object) array_merge([
            'id'                 => 1,
            'name'               => 'Test promo',
            'promo_type'         => PromoCampaignEntity::TYPE_PERCENT,
            'discount_percent'   => 10,
            'discount_fixed'     => null,
            'visible'            => 1,
            'has_date_range'     => 0,
            'date_start'         => null,
            'date_end'           => null,
            'min_order_amount'   => 0,
            'position'           => 0,
            'feed_enabled'       => 0,
            'exclude_no_image'   => 0,
            'badge_image'        => '',
        ], $overrides);
    }

    /**
     * Builds a Purchase mock with the given attributes, bypassing the constructor
     * (which resolves DiscountsHelper from the DI container).
     */
    protected function buildPurchase(array $overrides = []): Purchase
    {
        // createStub, а не MockBuilder: конструктор він вимикає сам, а очікувань
        // тут не ставлять — MockBuilder лише додавав нотис на кожен виклик.
        $purchase = $this->createStub(Purchase::class);

        $defaults = [
            'product_id'         => 1,
            'product_name'       => 'Product',
            'variant_id'         => 1,
            'amount'             => 1,
            'price'              => 100.0,
            'undiscounted_price' => 100.0,
            'sku'                => 'SKU-1',
            'units'              => 'pcs',
            'discounts'          => [],
        ];

        foreach (array_merge($defaults, $overrides) as $key => $value) {
            if (in_array($key, ['meta', 'variant', 'product'], true)) {
                continue; // handled explicitly below
            }
            $purchase->$key = $value;
        }

        $purchase->meta = $overrides['meta'] ?? (object) [
            'total_price'              => ($purchase->price * $purchase->amount),
            'undiscounted_total_price' => ($purchase->undiscounted_price * $purchase->amount),
        ];
        $purchase->variant = $overrides['variant'] ?? (object) ['id' => $purchase->variant_id, 'price' => $purchase->undiscounted_price];
        $purchase->product = $overrides['product'] ?? (object) ['id' => $purchase->product_id, 'main_image_id' => 10, 'brand_id' => 0];

        return $purchase;
    }

    protected function buildDiscount(string $sign, float $priceBefore, float $priceAfter, string $name = 'Promo'): Discount
    {
        $d = new Discount();
        $d->sign = $sign;
        $d->name = $name;
        $d->priceBeforeDiscount = $priceBefore;
        $d->priceAfterDiscount  = $priceAfter;
        $d->absoluteDiscount    = max(0.0, $priceBefore - $priceAfter);
        $d->percentDiscount     = $priceBefore > 0 ? round($d->absoluteDiscount / ($priceBefore / 100), 2) : 0.0;
        return $d;
    }
}
