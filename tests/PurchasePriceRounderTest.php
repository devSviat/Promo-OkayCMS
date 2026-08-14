<?php

namespace Modules\Sviat\Promo;

use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Promo\Services\PurchasePriceRounder;

require_once __DIR__ . '/PromoTestCase.php';

class PurchasePriceRounderTest extends PromoTestCase
{
    private function makeRounder(int $cents): PurchasePriceRounder
    {
        $factory = $this->mockEntityFactory([
            CurrenciesEntity::class => $this->mockCurrenciesEntity($cents),
        ]);
        return new PurchasePriceRounder($factory);
    }

    public function testRoundsToIntegerWhenCentsZero(): void
    {
        $rounder = $this->makeRounder(0);
        $purchaseDb = (object) [
            'price'              => 149.99,
            'undiscounted_price' => 199.99,
            'amount'             => 2,
        ];

        $result = $rounder->normalize($purchaseDb, 42);

        self::assertSame(150.0, $result->price);
        self::assertSame(200.0, $result->undiscounted_price);
    }

    public function testKeepsTwoDecimalsWhenCentsTwo(): void
    {
        $rounder = $this->makeRounder(2);
        $purchaseDb = (object) [
            'price'              => 149.991,
            'undiscounted_price' => 199.995,
            'amount'             => 1,
        ];

        $result = $rounder->normalize($purchaseDb, 42);

        self::assertSame(149.99, $result->price);
        self::assertSame(200.0, $result->undiscounted_price); // round-half-away-from-zero
    }

    public function testReturnsObjectUnchangedIfPriceMissing(): void
    {
        $rounder = $this->makeRounder(0);
        $purchaseDb = (object) [
            'product_id' => 1,
            'amount'     => 1,
        ];

        $result = $rounder->normalize($purchaseDb, 42);

        self::assertSame($purchaseDb, $result);
    }

    public function testDefaultsToTwoDecimalsWhenCurrencyMissing(): void
    {
        $factory = $this->mockEntityFactory([
            CurrenciesEntity::class => $this->createConfiguredMock(CurrenciesEntity::class, [
                'getMainCurrency' => (object) [],
            ]),
        ]);
        $rounder = new PurchasePriceRounder($factory);

        $purchaseDb = (object) ['price' => 12.345, 'undiscounted_price' => 12.345];
        $result = $rounder->normalize($purchaseDb, 0);

        self::assertSame(12.35, $result->price);
    }
}
