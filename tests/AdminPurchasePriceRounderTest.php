<?php

namespace Modules\Sviat\Promo;

use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Promo\Services\AdminPurchasePriceRounder;

require_once __DIR__ . '/PromoTestCase.php';

class AdminPurchasePriceRounderTest extends PromoTestCase
{
    private function makeRounder(int $cents): AdminPurchasePriceRounder
    {
        $factory = $this->mockEntityFactory([
            CurrenciesEntity::class => $this->mockCurrenciesEntity($cents),
        ]);
        return new AdminPurchasePriceRounder($factory);
    }

    public function testRoundsPriceToIntegerWhenCentsZero(): void
    {
        $rounder = $this->makeRounder(0);
        $purchase = (object) [
            'price'              => 229.5,
            'undiscounted_price' => 270.0,
            'amount'             => 1,
        ];

        $result = $rounder->normalize($purchase, null, null, []);

        self::assertSame(230.0, $result->price);
        self::assertSame(270.0, $result->undiscounted_price);
    }

    public function testKeepsTwoDecimalsWhenCentsTwo(): void
    {
        $rounder = $this->makeRounder(2);
        $purchase = (object) [
            'price'              => 229.50,
            'undiscounted_price' => 270.0,
        ];

        $result = $rounder->normalize($purchase, null, null, []);

        self::assertSame(229.5, $result->price);
    }

    public function testReturnsUnchangedIfPriceMissing(): void
    {
        $rounder = $this->makeRounder(0);
        $purchase = (object) ['product_id' => 1];

        $result = $rounder->normalize($purchase, null, null, []);

        self::assertSame($purchase, $result);
    }

    public function testDefaultsToTwoDecimalsWhenCurrencyMissing(): void
    {
        // createConfiguredStub() зʼявився після PHPUnit 9.5, а модуль їде і на стоку.
        $noCurrency = $this->createStub(CurrenciesEntity::class);
        $noCurrency->method('getMainCurrency')->willReturn((object) []);

        $factory = $this->mockEntityFactory([
            CurrenciesEntity::class => $noCurrency,
        ]);
        $rounder = new AdminPurchasePriceRounder($factory);

        $purchase = (object) ['price' => 12.345];

        $result = $rounder->normalize($purchase, null, null, []);

        self::assertSame(12.35, $result->price);
    }
}