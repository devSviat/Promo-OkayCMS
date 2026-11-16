<?php

namespace Modules\Sviat\Promo;

use Closure;
use Okay\Core\Cart;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\Money;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\FeaturesValuesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Core\QueryFactory;
use Okay\Helpers\XmlFeedHelper;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoScopeEntity;
use Okay\Modules\Sviat\Promo\Extenders\PromoGoogleMerchantExtender;
use Okay\Modules\Sviat\Promo\Plugins\ProductCampaignBlockPlugin;
use Okay\Modules\Sviat\Promo\Plugins\PromoBadgeStickerPlugin;
use Okay\Modules\Sviat\Promo\Services\PromoFeedPriceResolver;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;
use Okay\Modules\Sviat\Promo\Services\PromoUnitOffer;

require_once __DIR__ . '/PromoTestCase.php';

/**
 * Акція з мінімальною сумою замовлення на вітрині: варіант, одна одиниця якого
 * зі знижкою вже дає мінімум, показується як звичайна знижка; інший — лише умовою.
 * Кошик з однією такою одиницею мусить дати ту саму ціну.
 */
class PromoMinOrderDisplayTest extends PromoTestCase
{
    private function promo(array $overrides = []): \stdClass
    {
        return $this->buildPromo(array_merge([
            'id' => 7,
            'promo_type' => PromoCampaignEntity::TYPE_PERCENT,
            'discount_percent' => 10,
            'min_order_amount' => 1000,
        ], $overrides));
    }

    // ── Спільний предикат ─────────────────────────────────────────────────

    public function testPredicate(): void
    {
        $promo = $this->promo();

        self::assertTrue(PromotionEligibility::minOrderReachedByOneUnit($promo, 1200.0, 1080.0));
        self::assertFalse(PromotionEligibility::minOrderReachedByOneUnit($promo, 1000.0, 900.0));
        self::assertFalse(PromotionEligibility::minOrderReachedByOneUnit($promo, 1200.0, null), 'знижка для ціни не діє');
        self::assertFalse(PromotionEligibility::minOrderReachedByOneUnit($this->promo(['round_final_price' => 1]), 1111.0, 1000.0), 'кошик бачить 999.9');
        self::assertTrue(PromotionEligibility::minOrderReachedByOneUnit($this->promo(['min_order_amount' => 0]), 10.0, null));
        self::assertTrue(PromotionEligibility::minOrderReachedByOneUnit($this->promo(['min_order_amount' => '0.0000']), 10.0, 9.0));
    }

    // ── Кошик ─────────────────────────────────────────────────────────────

    /** Справжній PromotionEligibility: скоп акції — товари 1–3. */
    private function cartEligibility(): PromotionEligibility
    {
        $products = $this->createStub(ProductsEntity::class);
        $products->method('get')->willReturnCallback(static fn(int $id): object => (object) ['id' => $id, 'main_image_id' => 11, 'brand_id' => 0]);
        $campaigns = $this->createStub(PromoCampaignEntity::class);
        $campaigns->method('findOne')->willReturn((object) ['exclude_no_image' => 0]);
        $scope = $this->createStub(PromoScopeEntity::class);
        $scope->method('noLimit')->willReturnSelf();
        $scope->method('find')->willReturn([
            (object) ['promo_id' => 7, 'type' => 'product', 'object_id' => 1, 'exclude' => 0],
            (object) ['promo_id' => 7, 'type' => 'product', 'object_id' => 2, 'exclude' => 0],
            (object) ['promo_id' => 7, 'type' => 'product', 'object_id' => 3, 'exclude' => 0],
        ]);
        $categories = $this->createStub(CategoriesEntity::class);
        $categories->method('getProductCategories')->willReturn([]);
        $values = $this->createStub(FeaturesValuesEntity::class);
        $values->method('getProductValuesIds')->willReturn([]);

        return new PromotionEligibility($this->mockEntityFactory([
            ProductsEntity::class => $products,
            PromoCampaignEntity::class => $campaigns,
            PromoScopeEntity::class => $scope,
            CategoriesEntity::class => $categories,
            FeaturesValuesEntity::class => $values,
        ]));
    }

    private function oneUnitCart(float $price, ?float $totalAfterDiscounts = null): Cart
    {
        $cart = $this->createStub(Cart::class);
        $cart->isEmpty = false;
        $overrides = ['product_id' => 1, 'variant_id' => 100, 'price' => $price, 'undiscounted_price' => $price];
        if ($totalAfterDiscounts !== null) {
            $overrides['meta'] = (object) ['total_price' => $totalAfterDiscounts, 'undiscounted_total_price' => $price];
        }
        $cart->purchases = [$this->buildPurchase($overrides)];

        return $cart;
    }

    /** Що показано на картці як знижку, те кошик з однією одиницею і дає. */
    public function testCartWithOneGuaranteedUnitAppliesDiscount(): void
    {
        $eligibility = $this->cartEligibility();
        $promo = $this->promo();

        foreach ([null, 1080.0] as $afterRecalc) {
            $cart = $this->oneUnitCart(1200.0, $afterRecalc);
            self::assertTrue($eligibility->minOrderSatisfiedAfterOwnDiscount($promo, $cart));
            self::assertSame($promo, $eligibility->pickBestDiscountCampaignForPurchase($cart->purchases[0], $cart, [$promo]));
        }

        $conditional = $this->oneUnitCart(1000.0);
        self::assertFalse($eligibility->minOrderSatisfiedAfterOwnDiscount($promo, $conditional));
        self::assertNull($eligibility->pickBestDiscountCampaignForPurchase($conditional->purchases[0], $conditional, [$promo]));
    }

    /**
     * Перебір цін: якщо вітрина вважає варіант гарантованим, кошик з однією
     * одиницею теж дає знижку. Умовний варіант кошик з однією одиницею не знижує.
     */
    public function testPredicateNeverPromisesWhatCartRefuses(): void
    {
        $eligibility = $this->cartEligibility();
        $campaigns = [
            $this->promo(),
            $this->promo(['discount_percent' => 7]),
            $this->promo(['round_final_price' => 1]),
            $this->promo(['promo_type' => PromoCampaignEntity::TYPE_FIXED, 'discount_percent' => null, 'discount_fixed' => 300]),
        ];
        $guaranteed = 0;
        foreach ($campaigns as $promo) {
            for ($price = 1000.0; $price <= 1400.0; $price += 0.37) {
                $price = round($price, 2);
                $offer = PromoUnitOffer::of($promo, $price, 2);
                $shown = PromotionEligibility::minOrderReachedByOneUnit($promo, $price, $offer?->price);
                $cart = $this->oneUnitCart($price);
                $applied = $eligibility->minOrderSatisfiedAfterOwnDiscount($promo, $cart);
                if ($shown) {
                    $guaranteed++;
                    self::assertTrue($applied, "promo {$promo->promo_type}/{$promo->discount_percent} price {$price}");
                } elseif ((int) ($promo->round_final_price ?? 0) === 0) {
                    self::assertFalse($applied, "promo {$promo->promo_type}/{$promo->discount_percent} price {$price}");
                }
            }
        }
        self::assertGreaterThan(100, $guaranteed, 'перебір покриває обидва випадки');
    }

    // ── Блок акції на сторінці товару ─────────────────────────────────────

    private function plugin(): ProductCampaignBlockPlugin
    {
        $plugin = new \ReflectionClass(ProductCampaignBlockPlugin::class)->newInstanceWithoutConstructor();
        Closure::bind(function () {
            $this->currencyPrecision = 2;
        }, $plugin, ProductCampaignBlockPlugin::class)();

        return $plugin;
    }

    /** Товар без обробки сервісом вітрини: блок сам рахує ту саму ціну. */
    public function testPluginFallbackShowsGuaranteedPrice(): void
    {
        $promo = $this->promo();
        $product = (object) ['id' => 1, 'variant' => (object) ['id' => 100, 'price' => 1200.0]];

        self::accessible(new \ReflectionMethod(ProductCampaignBlockPlugin::class, 'computeEffectivePrice'))->invoke($this->plugin(), $promo, $product);

        self::assertSame(1080.0, $promo->sviat_promo_effective_price);
        self::assertSame(1080.0, $product->sviat_promo_discounted_unit_price);
        self::assertSame(10.0, $product->sviat_promo_discount_percent);
    }

    public function testPluginFallbackGivesConditionForConditionalVariant(): void
    {
        $promo = $this->promo();
        $product = (object) ['id' => 1, 'variant' => (object) ['id' => 100, 'price' => 1000.0]];

        self::accessible(new \ReflectionMethod(ProductCampaignBlockPlugin::class, 'computeEffectivePrice'))->invoke($this->plugin(), $promo, $product);

        self::assertObjectNotHasProperty('sviat_promo_effective_price', $promo);
        self::assertObjectNotHasProperty('sviat_promo_discounted_unit_price', $product);
        self::assertSame(900.0, $product->sviat_promo_conditions[100]->price);
        self::assertSame(1000.0, $product->variant->price);
    }

    /** Стікер умовної знижки виводиться лише там, де шаблон його просить (картки), і лише за наявності умови. */
    public function testConditionStickerRendersOnlyWhenAsked(): void
    {
        $design = $this->createStub(Design::class);
        $design->method('fetch')->willReturn('STICKER');
        $plugin = new PromoBadgeStickerPlugin($design);
        $withCondition = (object) ['id' => 1, 'sviat_promo_conditions' => [100 => (object) ['percent' => 10.0]]];

        self::assertSame('STICKER', $plugin->run(['product' => $withCondition, 'conditional' => 1]));
        self::assertSame('', $plugin->run(['product' => $withCondition]));
        self::assertSame('', $plugin->run(['product' => (object) ['id' => 1], 'conditional' => 1]));
        self::assertSame('STICKER', $plugin->run(['product' => (object) ['id' => 1, 'sviat_promo_badge_image' => 'a.png']]));
    }

    // ── Google Merchant / Merchant API ────────────────────────────────────

    public function testGoogleMerchantItemFollowsMinOrder(): void
    {
        $currencies = $this->createStub(CurrenciesEntity::class);
        $currencies->method('getMainCurrency')->willReturn((object) ['id' => 1, 'code' => 'UAH', 'cents' => 2]);
        $currencies->method('find')->willReturn([1 => (object) ['id' => 1, 'rate_from' => 1, 'rate_to' => 1]]);
        $resolver = new PromoFeedPriceResolver(
            $this->mockEntityFactory([CurrenciesEntity::class => $currencies]),
            $this->createStub(QueryFactory::class),
            $this->createStub(PromotionEligibility::class)
        );
        $promo = $this->promo(['feed_enabled' => 1]);
        Closure::bind(function () use ($promo) {
            $this->currentFeedType = 'gm';
            $this->currentFeedId = 5;
            $this->activeCampaigns = [7 => $promo];
            $this->feedLinksByCampaign = [7 => ['gm' => [5]]];
            $this->scopesByCampaign = [7 => [
                'inclusions' => ['has_rows' => true, 'products' => [1 => true], 'brands' => [], 'categories' => [], 'feature_groups' => []],
                'exclusions' => ['has_rows' => false, 'products' => [], 'brands' => [], 'categories' => [], 'feature_groups' => []],
            ]];
        }, $resolver, PromoFeedPriceResolver::class)();
        $money = $this->createStub(Money::class);
        $money->method('convert')->willReturnCallback(static fn($price): string => (string) $price);
        $feedHelper = $this->createStub(XmlFeedHelper::class);
        $feedHelper->method('escape')->willReturnArgument(0);
        $extender = new PromoGoogleMerchantExtender(
            $resolver,
            new PromotionEligibility($this->createStub(EntityFactory::class)),
            $money,
            $feedHelper,
            $this->createStub(EntityFactory::class)
        );
        Closure::bind(function () {
            $this->mainCurrency = (object) ['id' => 1, 'code' => 'UAH'];
        }, $extender, PromoGoogleMerchantExtender::class)();

        $guaranteed = $extender->applyPromoToItem([], (object) ['product_id' => 1, 'main_image_id' => 11, 'price' => 1200.0, 'currency_id' => 1]);
        self::assertSame('1080 UAH', $guaranteed['g:sale_price']['data']);
        self::assertSame('1200 UAH', $guaranteed['g:price']['data']);

        self::assertSame([], $extender->applyPromoToItem([], (object) ['product_id' => 1, 'main_image_id' => 11, 'price' => 1000.0, 'currency_id' => 1]));
    }
}
