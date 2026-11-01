<?php

namespace Modules\Sviat\Promo;

use Okay\Core\Classes\Discount;
use Okay\Modules\Sviat\Promo\Services\PromoDiscountForDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Збереження замовлення без змін не має міняти ціну, округлену акцією. */
class PromoDiscountForDbTest extends TestCase
{
    private function discount(string $type, float $value, float $before, float $after, string $sign = 'sviat_promo'): Discount
    {
        $discount = new Discount();
        $discount->sign = $sign;
        $discount->type = $type;
        $discount->value = $value;
        $discount->priceBeforeDiscount = $before;
        $discount->priceAfterDiscount = $after;
        $discount->absoluteDiscount = round($before - $after, 2);

        return $discount;
    }

    private function forDb(Discount $discount): object
    {
        $row = (object) ['type' => $discount->type, 'value' => $discount->value];

        return new PromoDiscountForDb()->keepActualAmount([$row, []], $discount, 'purchase', 1)[0];
    }

    /** Те, що робить ядро при збереженні замовлення: новий Discount із рядка БД. */
    private function recalculatedPrice(object $row, float $before): float
    {
        $restored = new Discount();
        $restored->type = $row->type;
        $restored->value = (float) $row->value;
        $restored->calculate($before);

        return round($before - $restored->absoluteDiscount, 2);
    }

    /** @return array<string, array{string, float, float, float}> */
    public static function roundedCases(): array
    {
        return [
            'percent rounded up'   => ['percent', 8.75, 1000.0, 913.0],
            'percent rounded down' => ['percent', 8.8, 1000.2, 912.0],
            'fixed rounded'        => ['absolute', 100.0, 1000.5, 901.0],
        ];
    }

    #[DataProvider('roundedCases')]
    public function testRoundedPriceSurvivesRecalculation(string $type, float $value, float $before, float $after): void
    {
        $row = $this->forDb($this->discount($type, $value, $before, $after));

        self::assertSame('absolute', $row->type);
        self::assertSame($after, $this->recalculatedPrice($row, $before));
    }

    public function testExactPercentStaysPercent(): void
    {
        $row = $this->forDb($this->discount('percent', 10.0, 1000.0, 900.0));

        self::assertSame('percent', $row->type);
        self::assertSame(10.0, $row->value);
    }

    public function testExactFixedStaysConfigured(): void
    {
        $row = $this->forDb($this->discount('absolute', 100.0, 1000.0, 900.0));

        self::assertSame(100.0, $row->value);
    }

    public function testForeignDiscountsAreLeftAlone(): void
    {
        $row = $this->forDb($this->discount('percent', 8.75, 1000.0, 913.0, 'coupon'));

        self::assertSame('percent', $row->type);
    }

    public function testRegistrationPointsAtTheCoreMethod(): void
    {
        $init = (string) file_get_contents(__DIR__ . '/../../../../Okay/Modules/Sviat/Promo/Init/Init.php');

        self::assertMatchesRegularExpression(
            "/DiscountsHelper::class, 'method' => 'prepareForDB'\\],\\s*\\['class' => PromoDiscountForDb::class, 'method' => 'keepActualAmount'\\]/",
            $init
        );
    }
}
