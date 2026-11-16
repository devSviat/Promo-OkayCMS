<?php

namespace Modules\Sviat\Promo;

use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Services\PromoProductDisplayService;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/PromoTestCase.php';

class PromoProductDisplayServiceTest extends PromoTestCase
{
    /** Мок логера передають лише тести, які на нього чекають. */
    private function makeService(int $cents, ?LoggerInterface $logger = null): array
    {
        $eligibility = $this->createStub(PromotionEligibility::class);
        $logger = $logger ?? $this->createStub(LoggerInterface::class);
        $entityFactory = $this->mockEntityFactory([
            CurrenciesEntity::class => $this->mockCurrenciesEntity($cents),
        ]);
        return [new PromoProductDisplayService($eligibility, $logger, $entityFactory), $logger];
    }

    public function testApplyPercentDiscountRoundsUnderCentsZero(): void
    {
        [$svc] = $this->makeService(0);
        $product = (object) ['id' => 1, 'variant' => (object) ['price' => 199.99]];
        $promo = $this->buildPromo(['promo_type' => PromoCampaignEntity::TYPE_PERCENT, 'discount_percent' => 25]);

        $svc->applyDiscountDisplay($product, $promo);

        self::assertSame(150.0, $product->variant->price);
        self::assertSame(199.99, $product->variant->compare_price);
        self::assertTrue($product->sviat_promo_price_display_applied);
    }

    public function testApplyPercentDiscountRoundsUnderCentsTwo(): void
    {
        [$svc] = $this->makeService(2);
        $product = (object) ['id' => 1, 'variant' => (object) ['price' => 199.99]];
        $promo = $this->buildPromo(['promo_type' => PromoCampaignEntity::TYPE_PERCENT, 'discount_percent' => 25]);

        $svc->applyDiscountDisplay($product, $promo);

        self::assertSame(149.99, $product->variant->price);
    }

    public function testApplyFixedDiscountUsesCurrencyPrecision(): void
    {
        [$svc] = $this->makeService(0);
        $product = (object) ['id' => 1, 'variant' => (object) ['price' => 99.49]];
        $promo = $this->buildPromo(['promo_type' => PromoCampaignEntity::TYPE_FIXED, 'discount_fixed' => 20]);

        $svc->applyDiscountDisplay($product, $promo);

        self::assertSame(79.0, $product->variant->price);
    }

    public function testApplyDiscountDisplayIgnoresZeroOrNegativeBasePrice(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        [$svc] = $this->makeService(2, $logger);
        $product = (object) ['id' => 1, 'variant' => (object) ['price' => 0]];
        $promo = $this->buildPromo();

        $svc->applyDiscountDisplay($product, $promo);

        self::assertFalse(isset($product->sviat_promo_price_display_applied));
    }

    public function testApplyDiscountDisplayDoesNothingForNullCampaign(): void
    {
        [$svc] = $this->makeService(2);
        $product = (object) ['id' => 1, 'variant' => (object) ['price' => 100]];
        $svc->applyDiscountDisplay($product, null);
        self::assertSame(100, $product->variant->price);
    }

    public function testApplyDiscountDisplayIgnoresInvalidPercent(): void
    {
        [$svc] = $this->makeService(2);
        $product = (object) ['id' => 1, 'variant' => (object) ['price' => 100]];
        $promo = $this->buildPromo(['discount_percent' => 200]);
        $svc->applyDiscountDisplay($product, $promo);
        self::assertSame(100, $product->variant->price);
    }

    /** @param object[] $campaigns */
    private function decorate(array $campaigns, float ...$prices): object
    {
        $eligibility = $this->createStub(PromotionEligibility::class);
        $eligibility->method('promoIdsForProduct')->willReturn(array_map(static fn(object $c): int => (int) $c->id, $campaigns));
        $eligibility->method('getActiveCampaigns')->willReturn($campaigns);
        $svc = new PromoProductDisplayService(
            $eligibility,
            $this->createStub(LoggerInterface::class),
            $this->mockEntityFactory([CurrenciesEntity::class => $this->mockCurrenciesEntity(2)])
        );

        $variants = [];
        foreach ($prices as $i => $price) {
            $variants[100 + $i] = (object) ['id' => 100 + $i, 'price' => $price, 'compare_price' => null];
        }
        $product = (object) ['id' => 1, 'variants' => $variants, 'variant' => reset($variants)];
        $svc->decorateProduct($product);

        return $product;
    }

    private function minOrderPromo(array $overrides = []): \stdClass
    {
        return $this->buildPromo(array_merge([
            'id' => 7,
            'promo_type' => PromoCampaignEntity::TYPE_PERCENT,
            'discount_percent' => 10,
            'min_order_amount' => 1000,
        ], $overrides));
    }

    /** Одна одиниця зі знижкою вже дає мінімальну суму: це звичайна знижка. */
    public function testGuaranteedVariantShowsDiscountDespiteMinOrder(): void
    {
        $p = $this->decorate([$this->minOrderPromo()], 1200.0);

        self::assertSame(1080.0, $p->variant->price);
        self::assertSame(1200.0, $p->variant->compare_price);
        self::assertSame(10.0, $p->sviat_promo_discount_percent);
        self::assertSame(1080.0, $p->sviat_promo_discounted_unit_price);
        self::assertTrue($p->sviat_promo_price_display_applied);
        self::assertObjectNotHasProperty('sviat_promo_conditions', $p);
    }

    /** Ціна зі знижкою рівно на мінімумі — теж гарантована. */
    public function testGuaranteedThresholdIsInclusive(): void
    {
        $fixed = $this->minOrderPromo(['promo_type' => PromoCampaignEntity::TYPE_FIXED, 'discount_percent' => null, 'discount_fixed' => 300]);

        self::assertSame(1000.0, $this->decorate([$fixed], 1300.0)->variant->price);
    }

    /** Ціна зі знижкою нижча за мінімум: ціну не чіпаємо, лише умова для стікера й рядка на картці. */
    public function testConditionalVariantKeepsPriceAndGetsCondition(): void
    {
        $p = $this->decorate([$this->minOrderPromo()], 1000.0);

        self::assertSame(1000.0, $p->variant->price);
        self::assertNull($p->variant->compare_price);
        self::assertObjectNotHasProperty('sviat_promo_price_display_applied', $p);
        self::assertObjectNotHasProperty('sviat_promo_discount_percent', $p);
        self::assertObjectNotHasProperty('sviat_promo_base_price', $p->variant);

        $condition = $p->sviat_promo_conditions[100];
        self::assertSame(7, $condition->promo_id);
        self::assertSame(PromoCampaignEntity::TYPE_PERCENT, $condition->promo_type);
        self::assertSame(10.0, $condition->percent);
        self::assertSame(900.0, $condition->price);
        self::assertSame(1000.0, $condition->min_order_amount);
    }

    /** Варіанти різні за ціною: кожен вирішується окремо. */
    public function testMixedVariantsDecideEachVariant(): void
    {
        $p = $this->decorate([$this->minOrderPromo()], 1000.0, 1200.0, 2000.0);
        [$cheap, $expensive, $top] = array_values($p->variants);

        self::assertSame(1000.0, $cheap->price);
        self::assertNull($cheap->compare_price);
        self::assertSame(1080.0, $expensive->price);
        self::assertSame(1200.0, $expensive->compare_price);
        self::assertSame(1800.0, $top->price);
        self::assertSame([100], array_keys($p->sviat_promo_conditions));
        self::assertObjectNotHasProperty('sviat_promo_price_display_applied', $p, 'основний варіант умовний');
    }

    public function testFixedCampaignGuaranteedAndConditional(): void
    {
        $fixed = $this->minOrderPromo(['promo_type' => PromoCampaignEntity::TYPE_FIXED, 'discount_percent' => null, 'discount_fixed' => 300]);
        $p = $this->decorate([$fixed], 1200.0, 1500.0);
        [$cheap, $expensive] = array_values($p->variants);

        self::assertSame(1200.0, $cheap->price);
        self::assertSame(1200.0, $expensive->price);
        self::assertSame(1500.0, $expensive->compare_price);

        $condition = $p->sviat_promo_conditions[100];
        self::assertSame(PromoCampaignEntity::TYPE_FIXED, $condition->promo_type);
        self::assertSame(300.0, $condition->discount_fixed);
        self::assertSame(25.0, $condition->percent);
        self::assertSame(900.0, $condition->price);
    }

    /**
     * Округлення вгору не робить знижку гарантованою: кошик перевіряє мінімум
     * за сумою без округлення (1111 − 10 % = 999.9 < 1000).
     */
    public function testRoundedUpPriceIsStillConditional(): void
    {
        $p = $this->decorate([$this->minOrderPromo(['round_final_price' => 1])], 1111.0);

        self::assertSame(1111.0, $p->variant->price);
        self::assertSame(1000.0, $p->sviat_promo_conditions[100]->price);
    }

    /** Кошик з однією одиницею пропустить умовну акцію і візьме наступну за пріоритетом. */
    public function testConditionalCampaignYieldsToGuaranteedLowerPriority(): void
    {
        $p = $this->decorate([
            $this->minOrderPromo(['discount_percent' => 20]),
            $this->buildPromo(['id' => 9, 'position' => 1, 'discount_percent' => 5]),
        ], 1000.0);

        self::assertSame(950.0, $p->variant->price);
        self::assertSame(5.0, $p->sviat_promo_discount_percent);
        self::assertObjectNotHasProperty('sviat_promo_conditions', $p);
    }

    public function testRepeatedDecorationKeepsCondition(): void
    {
        $eligibility = $this->createStub(PromotionEligibility::class);
        $eligibility->method('promoIdsForProduct')->willReturn([7]);
        $eligibility->method('getActiveCampaigns')->willReturn([$this->minOrderPromo()]);
        $svc = new PromoProductDisplayService($eligibility, $this->createStub(LoggerInterface::class), $this->mockEntityFactory([CurrenciesEntity::class => $this->mockCurrenciesEntity(2)]));
        $variant = (object) ['id' => 100, 'price' => 1000.0, 'compare_price' => null];
        $product = (object) ['id' => 1, 'variants' => [100 => $variant], 'variant' => $variant];

        $svc->decorateProduct($product);
        $svc->decorateProduct($product);

        self::assertSame(1000.0, $variant->price);
        self::assertSame(900.0, $product->sviat_promo_conditions[100]->price);
        self::assertCount(1, $product->sviat_promo_conditions);
    }

    /** Без мінімальної суми картка не отримує жодного нового поля. */
    public function testZeroMinOrderAddsNoConditionFields(): void
    {
        $p = $this->decorate([$this->minOrderPromo(['min_order_amount' => 0])], 1000.0, 50.0);

        self::assertSame(900.0, $p->variant->price);
        self::assertSame(45.0, array_values($p->variants)[1]->price);
        self::assertSame(
            ['id', 'variants', 'variant', 'sviat_promo_discount_percent', 'sviat_promo_discounted_unit_price', 'sviat_promo_price_display_applied'],
            array_keys((array) $p)
        );
        self::assertSame(['id', 'price', 'compare_price', 'sviat_promo_base_price'], array_keys((array) $p->variant));
    }
}
