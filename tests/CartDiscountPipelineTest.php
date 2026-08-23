<?php

namespace Modules\Sviat\Promo;

use Okay\Core\Cart;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Promo\Services\CartDiscountPipeline;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;

require_once __DIR__ . '/PromoTestCase.php';

class CartDiscountPipelineTest extends PromoTestCase
{
    private function makePipeline(int $cents): CartDiscountPipeline
    {
        $eligibility = $this->createStub(PromotionEligibility::class);
        $eligibility->method('lineIsBonusGift')->willReturn(false);
        $factory = $this->mockEntityFactory([
            CurrenciesEntity::class => $this->mockCurrenciesEntity($cents),
        ]);
        return new CartDiscountPipeline($factory, $eligibility);
    }

    private function invokeNormalize(CartDiscountPipeline $pipeline, Cart $cart): void
    {
        $ref = new \ReflectionClass($pipeline);
        $m = self::accessible($ref->getMethod('normalizeAppliedPromoRounding'));
        $m->invoke($pipeline, $cart);
    }

    public function testNormalizeRoundsLineToCentsZero(): void
    {
        $pipeline = $this->makePipeline(0);

        $discount = $this->buildDiscount('sviat_promo', 199.99, 149.99);
        $purchase = $this->buildPurchase([
            'price' => 149.99,
            'undiscounted_price' => 199.99,
            'amount' => 2,
            'discounts' => [$discount],
        ]);
        $purchase->meta->total_price = 299.98;
        $purchase->meta->undiscounted_total_price = 399.98;

        $cart = $this->createStub(Cart::class);
        $cart->purchases = [$purchase];

        $this->invokeNormalize($pipeline, $cart);

        self::assertSame(300.0, $purchase->meta->total_price);
        self::assertSame(150.0, $purchase->price);
        self::assertSame(200.0, $discount->priceBeforeDiscount);
        self::assertSame(150.0, $discount->priceAfterDiscount);
    }

    public function testNormalizeKeepsTwoDecimalsWhenCentsTwo(): void
    {
        $pipeline = $this->makePipeline(2);

        $discount = $this->buildDiscount('sviat_promo', 199.99, 149.99);
        $purchase = $this->buildPurchase([
            'price' => 149.99,
            'undiscounted_price' => 199.99,
            'amount' => 2,
            'discounts' => [$discount],
        ]);
        $purchase->meta->total_price = 299.98;
        $purchase->meta->undiscounted_total_price = 399.98;

        $cart = $this->createStub(Cart::class);
        $cart->purchases = [$purchase];

        $this->invokeNormalize($pipeline, $cart);

        self::assertSame(299.98, $purchase->meta->total_price);
        self::assertSame(149.99, $purchase->price);
    }

    public function testNormalizeSkipsPurchaseWithoutSviatPromoDiscount(): void
    {
        $pipeline = $this->makePipeline(0);

        $purchase = $this->buildPurchase([
            'price' => 149.99,
            'amount' => 1,
            'discounts' => [],
        ]);
        $purchase->meta->total_price = 149.99;

        $cart = $this->createStub(Cart::class);
        $cart->purchases = [$purchase];

        $this->invokeNormalize($pipeline, $cart);

        self::assertSame(149.99, $purchase->meta->total_price);
        self::assertSame(149.99, $purchase->price);
    }
}
