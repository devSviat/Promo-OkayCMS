<?php

namespace Modules\Sviat\Promo;

use Okay\Core\Cart;
use Okay\Core\Classes\Discount;
use Okay\Core\Classes\Purchase;
use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Entities\CurrenciesEntity;
use Okay\Helpers\DiscountsHelper;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoQuantityTierEntity;
use Okay\Modules\Sviat\Promo\Extenders\PromoCartHooks;
use Okay\Modules\Sviat\Promo\Init\Init;
use Okay\Modules\Sviat\Promo\Requests\CampaignPayloadRequest;
use Okay\Modules\Sviat\Promo\Services\CartDiscountPipeline;
use Okay\Modules\Sviat\Promo\Services\PromoPriceRounding;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;
use Okay\Modules\Sviat\Promo\Services\QuantityTierCalculator;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/PromoTestCase.php';

class QuantityDiscountTest extends PromoTestCase
{
    private const array TIERS = [
        ['min_quantity' => 2, 'percent' => 5],
        ['min_quantity' => 3, 'percent' => 7.5],
        ['min_quantity' => 4, 'percent' => 10],
    ];

    public function testHighestReachedTierApplies(): void
    {
        self::assertSame(0.0, QuantityTierCalculator::percentForQuantity(self::TIERS, 1));
        self::assertSame(5.0, QuantityTierCalculator::percentForQuantity(self::TIERS, 2));
        self::assertSame(7.5, QuantityTierCalculator::percentForQuantity(self::TIERS, 3));
        self::assertSame(10.0, QuantityTierCalculator::percentForQuantity(self::TIERS, 9));
    }

    public function testTierOrderAndRowShapeDoNotMatter(): void
    {
        $tiers = [(object) ['min_quantity' => 4, 'percent' => '10.00'], (object) ['min_quantity' => 2, 'percent' => '5.00']];

        self::assertSame(5.0, QuantityTierCalculator::percentForQuantity($tiers, 3));
    }

    public function testInvalidTiersAreIgnored(): void
    {
        $tiers = [['min_quantity' => 1, 'percent' => 50], ['min_quantity' => 2, 'percent' => 150], ['min_quantity' => 3, 'percent' => 0]];

        self::assertSame(0.0, QuantityTierCalculator::percentForQuantity($tiers, 5));
    }

    public function testRoundingOffKeepsCurrencyPrecision(): void
    {
        $promo = $this->buildPromo(['round_final_price' => 0]);

        self::assertSame(323.75, PromoPriceRounding::price($promo, 323.75, 2, 350.0));
    }

    public function testRoundingOnRoundsHalfUpToWholeNumber(): void
    {
        $promo = $this->buildPromo(['round_final_price' => 1]);

        self::assertSame(324.0, PromoPriceRounding::price($promo, 323.75, 2, 350.0));
        self::assertSame(333.0, PromoPriceRounding::price($promo, 332.5, 2, 350.0));
        self::assertSame(912.0, PromoPriceRounding::price($promo, 912.48, 2, 960.0));
    }

    public function testRoundingNeverRaisesPriceAboveRegular(): void
    {
        $promo = $this->buildPromo(['round_final_price' => 1]);

        self::assertSame(99.0, PromoPriceRounding::price($promo, 99.6, 2, 99.7));
    }

    public function testQuantityCountsUnitsOfAllScopeLines(): void
    {
        $eligibility = $this->eligibilityWithScope([1, 2]);
        $first = $this->buildPurchase(['product_id' => 1, 'amount' => 2]);
        $second = $this->buildPurchase(['product_id' => 2, 'amount' => 1]);
        $outside = $this->buildPurchase(['product_id' => 3, 'amount' => 5]);
        $cart = $this->cartWith([$first, $second, $outside]);
        $promo = $this->quantityPromo();

        self::assertSame(7.5, $eligibility->quantityPercentForPurchase($first, $cart, $promo));
        self::assertSame(7.5, $eligibility->quantityPercentForPurchase($second, $cart, $promo));
        self::assertSame(0.0, $eligibility->quantityPercentForPurchase($outside, $cart, $promo));
    }

    public function testGiftLinesDoNotReachTier(): void
    {
        $eligibility = $this->eligibilityWithScope([1]);
        $paid = $this->buildPurchase(['product_id' => 1, 'amount' => 1]);
        $gift = $this->buildPurchase([
            'product_id' => 1,
            'amount' => 1,
            'variant' => (object) ['id' => 2, 'price' => 0, 'gift_product_id' => 1],
        ]);
        $cart = $this->cartWith([$paid, $gift]);

        self::assertSame(0.0, $eligibility->quantityPercentForPurchase($paid, $cart, $this->quantityPromo()));
    }

    public function testOtherPromoTypesGetNoQuantityPercent(): void
    {
        $eligibility = $this->eligibilityWithScope([1]);
        $purchase = $this->buildPurchase(['product_id' => 1, 'amount' => 4]);
        $promo = $this->buildPromo(['id' => 7, 'promo_type' => PromoCampaignEntity::TYPE_PERCENT]);

        self::assertSame(0.0, $eligibility->quantityPercentForPurchase($purchase, $this->cartWith([$purchase]), $promo));
    }

    public function testPostedTiersAreSortedAndAcceptCommaDecimals(): void
    {
        $tiers = $this->postTiers([
            'new1' => ['min_quantity' => '4', 'percent' => '10'],
            '1' => ['min_quantity' => '2', 'percent' => '7,5'],
        ]);

        self::assertSame([
            ['min_quantity' => 2, 'percent' => 7.5],
            ['min_quantity' => 4, 'percent' => 10.0],
        ], $tiers);
    }

    /** @return iterable<string, array{array<string, array<string, string>>}> */
    public static function invalidTierSets(): iterable
    {
        yield 'одна штука' => [['a' => ['min_quantity' => '1', 'percent' => '5']]];
        yield 'понад 100 %' => [['a' => ['min_quantity' => '2', 'percent' => '101']]];
        yield 'нульовий відсоток' => [['a' => ['min_quantity' => '2', 'percent' => '0']]];
        yield 'однакові пороги' => [['a' => ['min_quantity' => '2', 'percent' => '5'], 'b' => ['min_quantity' => '2', 'percent' => '7']]];
        yield 'не число' => [['a' => ['min_quantity' => '2', 'percent' => '5%']]];
        yield 'дробова кількість' => [['a' => ['min_quantity' => '2.5', 'percent' => '5']]];
    }

    /**
     * Будь-який зіпсований рядок відкидає весь набір: інакше акція мовчки збереглась би з іншою шкалою.
     *
     * @param array<string, array<string, string>> $raw
     */
    #[DataProvider('invalidTierSets')]
    public function testInvalidPostedTiersRejectWholeSet(array $raw): void
    {
        self::assertSame([], $this->postTiers($raw));
    }

    /** Кошик перераховується на тих самих Purchase: поріг для 2 шт. не має дожити до 1 шт. */
    public function testStaleQuantityCandidateIsClearedBeforeRecalculation(): void
    {
        $hooks = new \ReflectionClass(PromoCartHooks::class)->newInstanceWithoutConstructor();
        $foreign = new Discount();
        $purchase = $this->buildPurchase();
        $purchase->availableDiscounts = ['sviat_promo' => new Discount(), 'coupon' => $foreign];
        $purchase->meta->sviat_promo_round_final_price = true;
        $cart = $this->cartWith([$purchase]);

        self::accessible(new \ReflectionMethod($hooks, 'clearPreviousPromoCandidates'))->invoke($hooks, $cart);

        self::assertSame(['coupon' => $foreign], $purchase->availableDiscounts);
        self::assertFalse(isset($purchase->meta->sviat_promo_round_final_price));
    }

    public function testCartTotalKeepsSharedPercent(): void
    {
        $total = $this->aggregate([$this->percentDiscount(5, 350, 333), $this->percentDiscount(5, 645, 613)]);

        self::assertSame(5.0, $total->percentDiscount);
    }

    public function testCartTotalWithDifferentPercentsShowsEffectiveRate(): void
    {
        $total = $this->aggregate([$this->percentDiscount(5, 100, 95), $this->percentDiscount(10, 100, 90)]);

        self::assertSame(7.5, $total->percentDiscount);
        self::assertSame(15.0, $total->absoluteDiscount);
    }

    /** До 1.0.19 точність ішла двома числами, і setTypeDecimal() губив дробову частину. */
    public function testMoneyColumnsKeepFractionalDigits(): void
    {
        $types = [];
        foreach (['moneyFields', 'quantityTierFields'] as $method) {
            foreach (self::accessible(new \ReflectionMethod(Init::class, $method))->invoke(null) as $field) {
                $types[$field->getName()] = $field->getType();
            }
        }

        self::assertSame('decimal(14,4)', $types['min_order_amount']);
        self::assertSame('decimal(10,2)', $types['discount_percent']);
        self::assertSame('decimal(14,4)', $types['discount_fixed']);
        self::assertSame('decimal(5,2)', $types['percent']);
    }

    private function quantityPromo(): \stdClass
    {
        return $this->buildPromo(['id' => 7, 'promo_type' => PromoCampaignEntity::TYPE_QUANTITY]);
    }

    /** @param int[] $productIds товари в області дії акції */
    private function eligibilityWithScope(array $productIds): PromotionEligibility
    {
        $tiers = $this->createStub(PromoQuantityTierEntity::class);
        $tiers->method('noLimit')->willReturnSelf();
        $tiers->method('find')->willReturn(array_map(static fn(array $t): object => (object) $t, self::TIERS));
        $factory = $this->mockEntityFactory([PromoQuantityTierEntity::class => $tiers]);

        return new class ($factory, $productIds) extends PromotionEligibility {
            /** @param int[] $scope */
            public function __construct(EntityFactory $factory, private array $scope)
            {
                parent::__construct($factory);
            }

            public function purchaseMatchesCampaign(Purchase $purchase, int $campaignId): bool
            {
                return in_array((int) $purchase->product_id, $this->scope, true);
            }
        };
    }

    /** @param Purchase[] $purchases */
    private function cartWith(array $purchases): Cart
    {
        $cart = $this->createStub(Cart::class);
        $cart->purchases = $purchases;

        return $cart;
    }

    /** @param array<string, array<string, string>> $raw */
    private function postTiers(array $raw): array
    {
        // Заглушку PHPUnit не створити: у Request є метод з назвою method().
        $request = new class ($raw) extends Request {
            /** @param array<string, array<string, string>> $raw */
            public function __construct(private array $raw)
            {
            }

            public function post($name = null, $type = null, $default = null)
            {
                return $name === 'quantity_tiers' ? $this->raw : $default;
            }
        };

        return new CampaignPayloadRequest($request)->postQuantityTiers();
    }

    private function percentDiscount(float $percent, float $before, float $after): Discount
    {
        $discount = $this->buildDiscount('sviat_promo', $before, $after);
        $discount->type = 'percent';
        $discount->value = $percent;

        return $discount;
    }

    /** @param Discount[] $discounts */
    private function aggregate(array $discounts): Discount
    {
        $eligibility = $this->createStub(PromotionEligibility::class);
        $factory = $this->mockEntityFactory([CurrenciesEntity::class => $this->mockCurrenciesEntity(2)]);
        $pipeline = new CartDiscountPipeline($factory, $eligibility, $this->createStub(DiscountsHelper::class));
        $cart = $this->cartWith([]);
        $cart->total_purchases_discounts = [];
        $append = self::accessible(new \ReflectionMethod($pipeline, 'appendToCartPurchaseDiscountTotals'));
        foreach ($discounts as $discount) {
            $append->invoke($pipeline, $cart, $discount, 1);
        }

        return $cart->total_purchases_discounts['sviat_promo'];
    }
}
