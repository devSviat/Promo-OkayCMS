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
    private function makeService(int $cents): array
    {
        $eligibility = $this->createMock(PromotionEligibility::class);
        $logger = $this->createMock(LoggerInterface::class);
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
        [$svc, $logger] = $this->makeService(2);
        $logger->expects(self::once())->method('warning');
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
}
