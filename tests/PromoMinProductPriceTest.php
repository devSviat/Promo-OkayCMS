<?php

namespace Modules\Sviat\Promo;

use Closure;
use Okay\Core\Cart;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\EntityMigrator;
use Okay\Core\Modules\SqlPresentor;
use Okay\Core\Money;
use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\Select;
use Okay\Core\QueryFactory\SqlQuery;
use Okay\Core\Request;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\DiscountsEntity;
use Okay\Entities\FeaturesValuesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Entities\ProductsEntity;
use Okay\Entities\PurchasesEntity;
use Okay\Helpers\DiscountsHelper;
use Okay\Helpers\XmlFeedHelper;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoScopeEntity;
use Okay\Modules\Sviat\Promo\ExtendsEntities\ProductsPromoFilter;
use Okay\Modules\Sviat\Promo\Extenders\PromoCartHooks;
use Okay\Modules\Sviat\Promo\Extenders\PromoFeedsExtender;
use Okay\Modules\Sviat\Promo\Extenders\PromoGoogleMerchantExtender;
use Okay\Modules\Sviat\Promo\Init\Init;
use Okay\Modules\Sviat\Promo\Plugins\MoneyInputModifier;
use Okay\Modules\Sviat\Promo\Plugins\ProductCampaignBlockPlugin;
use Okay\Modules\Sviat\Promo\Requests\CampaignPayloadRequest;
use Okay\Modules\Sviat\Promo\Services\AdminOrderPromoApplier;
use Okay\Modules\Sviat\Promo\Services\CartDiscountPipeline;
use Okay\Modules\Sviat\Promo\Services\PromoFeedPriceResolver;
use Okay\Modules\Sviat\Promo\Services\PromoProductDisplayService;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/PromoTestCase.php';

/**
 * Умова «Ціна товару від N» (min_product_price): знижка діє лише на варіанти,
 * що до неї коштують не менше за N. Вітрина, фіди, кошик і адмінка замовлення
 * мають давати ту саму відповідь.
 */
class PromoMinProductPriceTest extends PromoTestCase
{
    private const THRESHOLD = 1000;

    private function percent(array $overrides = []): \stdClass
    {
        return $this->buildPromo(array_merge([
            'id' => 7,
            'name' => 'Від 1000',
            'promo_type' => PromoCampaignEntity::TYPE_PERCENT,
            'discount_percent' => 10,
            'min_product_price' => self::THRESHOLD,
        ], $overrides));
    }

    // ── Вітрина ───────────────────────────────────────────────────────────

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

    public function testStorefrontShowsDiscountWhenPriceMeetsThreshold(): void
    {
        $p = $this->decorate([$this->percent()], 1200.0);

        self::assertSame(1200.0, $p->variant->compare_price);
        self::assertSame(1080.0, $p->variant->price);
        self::assertSame(1080.0, $p->sviat_promo_discounted_unit_price);
        self::assertTrue($p->sviat_promo_price_display_applied);
    }

    public function testStorefrontThresholdIsInclusive(): void
    {
        self::assertSame(900.0, $this->decorate([$this->percent()], 1000.0)->variant->price);
    }

    public function testStorefrontKeepsRegularPriceBelowThreshold(): void
    {
        $p = $this->decorate([$this->percent()], 999.99);

        self::assertSame(999.99, $p->variant->price);
        self::assertNull($p->variant->compare_price);
        self::assertObjectNotHasProperty('sviat_promo_price_display_applied', $p);
        self::assertObjectNotHasProperty('sviat_promo_discount_percent', $p);
    }

    public function testZeroThresholdMeansNoLimit(): void
    {
        self::assertSame(45.0, $this->decorate([$this->percent(['min_product_price' => 0])], 50.0)->variant->price);
        self::assertSame(45.0, $this->decorate([$this->percent(['min_product_price' => '0.0000'])], 50.0)->variant->price);
        $legacy = $this->percent();
        unset($legacy->min_product_price);
        self::assertSame(45.0, $this->decorate([$legacy], 50.0)->variant->price, 'кампанія без колонки (до міграції)');
    }

    public function testFixedCampaignFollowsThreshold(): void
    {
        $fixed = $this->percent(['promo_type' => PromoCampaignEntity::TYPE_FIXED, 'discount_percent' => null, 'discount_fixed' => 150]);

        self::assertSame(1050.0, $this->decorate([$fixed], 1200.0)->variant->price);
        self::assertSame(800.0, $this->decorate([$fixed], 800.0)->variant->price);
    }

    /** Поріг перевіряється для кожного варіанта: основний дешевший, другий дорожчий. */
    public function testVariantMixDiscountsOnlyVariantsAboveThreshold(): void
    {
        $p = $this->decorate([$this->percent()], 800.0, 1200.0, 1000.0);
        [$cheap, $expensive, $edge] = array_values($p->variants);

        self::assertSame(800.0, $cheap->price);
        self::assertNull($cheap->compare_price);
        self::assertSame(1080.0, $expensive->price);
        self::assertSame(1200.0, $expensive->compare_price);
        self::assertSame(900.0, $edge->price);
        self::assertObjectNotHasProperty('sviat_promo_price_display_applied', $p, 'картка показує основний варіант, він без знижки');
    }

    /** Варіант нижче порогу акції бере наступну за пріоритетом акцію без порогу. */
    public function testVariantBelowThresholdFallsBackToNextCampaign(): void
    {
        $p = $this->decorate([
            $this->percent(['discount_percent' => 30]),
            $this->percent(['id' => 9, 'discount_percent' => 5, 'min_product_price' => 0]),
        ], 800.0, 1200.0);
        [$cheap, $expensive] = array_values($p->variants);

        self::assertSame(760.0, $cheap->price);
        self::assertSame(840.0, $expensive->price);
        self::assertSame(5.0, $p->sviat_promo_discount_percent);
    }

    public function testRepeatedDecorationDoesNotDiscountTwice(): void
    {
        $eligibility = $this->createStub(PromotionEligibility::class);
        $eligibility->method('promoIdsForProduct')->willReturn([7]);
        $eligibility->method('getActiveCampaigns')->willReturn([$this->percent()]);
        $svc = new PromoProductDisplayService($eligibility, $this->createStub(LoggerInterface::class), $this->mockEntityFactory([CurrenciesEntity::class => $this->mockCurrenciesEntity(2)]));
        $cheap = (object) ['id' => 1, 'price' => 800.0, 'compare_price' => null];
        $expensive = (object) ['id' => 2, 'price' => 1200.0, 'compare_price' => null];
        $product = (object) ['id' => 1, 'variants' => [1 => $cheap, 2 => $expensive], 'variant' => $cheap];

        $svc->decorateProduct($product);
        $svc->decorateProduct($product);

        self::assertSame(1080.0, $expensive->price);
        self::assertSame(1200.0, $expensive->compare_price);
        self::assertSame(800.0, $cheap->price);
    }

    /** Блок акції на сторінці товару бере ту саму акцію, що й ціна. */
    public function testCampaignBlockSkipsCampaignWhoseThresholdIsNotMet(): void
    {
        $threshold = $this->percent();
        $plain = $this->percent(['id' => 9, 'min_product_price' => 0]);
        $eligibility = $this->createStub(PromotionEligibility::class);
        $eligibility->method('getActiveCampaigns')->willReturn([$threshold, $plain]);
        $plugin = new \ReflectionClass(ProductCampaignBlockPlugin::class)->newInstanceWithoutConstructor();
        self::accessible(new \ReflectionProperty(ProductCampaignBlockPlugin::class, 'promotionEligibility'))->setValue($plugin, $eligibility);
        $pick = self::accessible(new \ReflectionMethod(ProductCampaignBlockPlugin::class, 'campaignForPrice'));
        $base = self::accessible(new \ReflectionMethod(ProductCampaignBlockPlugin::class, 'basePrice'));

        self::assertSame($threshold, $pick->invoke($plugin, [7, 9], 1200.0));
        self::assertSame($plain, $pick->invoke($plugin, [7, 9], 800.0));
        // Після PromoProductDisplayService ціна варіанта вже знижена; поріг рахують від звичайної.
        self::assertSame(1050.0, $base->invoke($plugin, (object) ['price' => 945.0, 'sviat_promo_base_price' => 1050.0]));
        self::assertSame(800.0, $base->invoke($plugin, (object) ['price' => 800.0]));
    }

    // ── Фіди ──────────────────────────────────────────────────────────────

    private function currencies(): CurrenciesEntity
    {
        $currencies = $this->createStub(CurrenciesEntity::class);
        $currencies->method('getMainCurrency')->willReturn((object) ['id' => 1, 'code' => 'UAH', 'cents' => 2, 'rate_from' => 1, 'rate_to' => 1]);
        $currencies->method('find')->willReturn([
            1 => (object) ['id' => 1, 'code' => 'UAH', 'rate_from' => 1, 'rate_to' => 1],
            2 => (object) ['id' => 2, 'code' => 'USD', 'rate_from' => 1, 'rate_to' => 40],
        ]);

        return $currencies;
    }

    /** Резолвер фіду Feeds із двома акціями, прив'язаними до фіду 99, на товар 1. */
    private function loadedResolver(object ...$campaigns): PromoFeedPriceResolver
    {
        $resolver = new PromoFeedPriceResolver(
            $this->mockEntityFactory([CurrenciesEntity::class => $this->currencies()]),
            $this->createStub(QueryFactory::class),
            $this->createStub(PromotionEligibility::class)
        );
        $active = $links = $scopes = [];
        foreach ($campaigns as $campaign) {
            $id = (int) $campaign->id;
            $active[$id] = $campaign;
            $links[$id] = ['feeds' => [99]];
            $scopes[$id] = [
                'inclusions' => ['has_rows' => true, 'products' => [1 => true], 'brands' => [], 'categories' => [], 'feature_groups' => []],
                'exclusions' => ['has_rows' => false, 'products' => [], 'brands' => [], 'categories' => [], 'feature_groups' => []],
            ];
        }
        Closure::bind(function () use ($active, $links, $scopes) {
            $this->currentFeedType = 'feeds';
            $this->currentFeedId = 99;
            $this->activeCampaigns = $active;
            $this->feedLinksByCampaign = $links;
            $this->scopesByCampaign = $scopes;
        }, $resolver, PromoFeedPriceResolver::class)();

        return $resolver;
    }

    public function testFeedResolverAppliesThresholdPerVariant(): void
    {
        $resolver = $this->loadedResolver($this->percent());

        self::assertSame(7, (int) $resolver->findBestCampaign(1, 0, 0, 11, 1200.0)->id);
        self::assertSame(7, (int) $resolver->findBestCampaign(1, 0, 0, 11, 1000.0)->id);
        self::assertNull($resolver->findBestCampaign(1, 0, 0, 11, 999.0));

        $withFallback = $this->loadedResolver($this->percent(), $this->percent(['id' => 9, 'position' => 1, 'min_product_price' => 0]));
        self::assertSame(9, (int) $withFallback->findBestCampaign(1, 0, 0, 11, 800.0)->id);
    }

    public function testFeedsItemGetsSamePriceAsStorefront(): void
    {
        $extender = new PromoFeedsExtender($this->loadedResolver($this->percent()), $this->createStub(XmlFeedHelper::class));

        $above = $extender->attachPromoToProduct((object) ['product_id' => 1, 'main_image_id' => 11, 'price' => 1200.0, 'currency_id' => 1]);
        self::assertSame(1080.0, $above->price);
        self::assertSame(1200.0, $above->compare_price);

        $below = $extender->attachPromoToProduct((object) ['product_id' => 1, 'main_image_id' => 11, 'price' => 800.0, 'currency_id' => 1]);
        self::assertSame(800.0, $below->price);
        self::assertObjectNotHasProperty('compare_price', $below);
    }

    /** Рядок фіду в доларах: поріг у гривнях порівнюється з перерахованою ціною, як на вітрині. */
    public function testFeedsThresholdUsesMainCurrencyPrice(): void
    {
        $extender = new PromoFeedsExtender($this->loadedResolver($this->percent()), $this->createStub(XmlFeedHelper::class));

        self::assertSame(27.0, $extender->attachPromoToProduct((object) ['product_id' => 1, 'main_image_id' => 11, 'price' => 30.0, 'currency_id' => 2])->price);
        self::assertSame(20.0, $extender->attachPromoToProduct((object) ['product_id' => 1, 'main_image_id' => 11, 'price' => 20.0, 'currency_id' => 2])->price);
    }

    /**
     * Фід читає товари небуферизованим запитом: будь-який запит до БД посеред
     * циклу падає. Валюти мають бути завантажені вже в preload().
     */
    public function testFeedCurrenciesAreLoadedBeforeItemLoop(): void
    {
        $finds = 0;
        $currencies = $this->createStub(CurrenciesEntity::class);
        $currencies->method('getMainCurrency')->willReturn((object) ['id' => 1, 'cents' => 2]);
        $currencies->method('find')->willReturnCallback(function () use (&$finds): array {
            $finds++;

            return [(object) ['id' => 2, 'rate_from' => 1, 'rate_to' => 40]];
        });
        $campaigns = $this->createStub(PromoCampaignEntity::class);
        $campaigns->method('noLimit')->willReturnSelf();
        $campaigns->method('find')->willReturn([]);
        $resolver = new PromoFeedPriceResolver(
            $this->mockEntityFactory([CurrenciesEntity::class => $currencies, PromoCampaignEntity::class => $campaigns]),
            $this->createStub(QueryFactory::class),
            $this->createStub(PromotionEligibility::class)
        );

        $resolver->preload();
        self::assertSame(1, $finds, 'preload() вантажить валюти');

        self::assertSame(1200.0, $resolver->priceInMainCurrency(30.0, 2));
        self::assertSame(1200.0, $resolver->priceInMainCurrency(30.0, '2'));
        self::assertSame(1, $finds, 'у циклі товарів запитів немає');
    }

    /** GoogleMerchant і Sviat/MerchantApi ідуть через PromoGoogleMerchantExtender. */
    public function testGoogleMerchantItemFollowsThreshold(): void
    {
        $resolver = $this->loadedResolver($this->percent());
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

        $above = $extender->applyPromoToItem([], (object) ['product_id' => 1, 'main_image_id' => 11, 'price' => 1200.0, 'currency_id' => 1]);
        self::assertSame('1080 UAH', $above['g:sale_price']['data']);
        self::assertSame('1200 UAH', $above['g:price']['data']);

        self::assertSame([], $extender->applyPromoToItem([], (object) ['product_id' => 1, 'main_image_id' => 11, 'price' => 800.0, 'currency_id' => 1]));
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

    private function cart(float ...$prices): Cart
    {
        $cart = $this->createStub(Cart::class);
        $cart->isEmpty = $prices === [];
        $cart->purchases = [];
        foreach ($prices as $i => $price) {
            $cart->purchases[] = $this->buildPurchase(['product_id' => $i + 1, 'variant_id' => 100 + $i, 'price' => $price, 'undiscounted_price' => $price]);
        }

        return $cart;
    }

    public function testCartDiscountsOnlyLinesAboveThreshold(): void
    {
        $eligibility = $this->cartEligibility();
        $cart = $this->cart(1200.0, 800.0, 1000.0);
        [$expensive, $cheap, $edge] = $cart->purchases;
        $promo = $this->percent();

        self::assertSame($promo, $eligibility->pickBestDiscountCampaignForPurchase($expensive, $cart, [$promo]));
        self::assertNull($eligibility->pickBestDiscountCampaignForPurchase($cheap, $cart, [$promo]));
        self::assertSame($promo, $eligibility->pickBestDiscountCampaignForPurchase($edge, $cart, [$promo]));
        $noLimit = $this->percent(['min_product_price' => 0]);
        self::assertSame($noLimit, $eligibility->pickBestDiscountCampaignForPurchase($cheap, $cart, [$noLimit]));
    }

    /** Основний шлях кошика: знижка `sviat_promo` додається в availableDiscounts лише рядку вище порогу. */
    public function testCartHookAttachesDiscountOnlyAboveThreshold(): void
    {
        $eligibility = $this->cartEligibility();
        $campaigns = $this->createStub(PromoCampaignEntity::class);
        $campaigns->method('noLimit')->willReturnSelf();
        $campaigns->method('find')->willReturn([$this->percent()]);
        $hooks = $this->cartHooks($eligibility, $campaigns);
        $cart = $this->cart(1200.0, 800.0);

        $hooks->attachSviatPromoPurchaseDiscounts($cart);

        [$expensive, $cheap] = $cart->purchases;
        self::assertSame(10.0, (float) $expensive->availableDiscounts['sviat_promo']->value);
        self::assertArrayNotHasKey('sviat_promo', (array) ($cheap->availableDiscounts ?? []));
    }

    /**
     * Обидві умови разом: знижка діє, коли виконано і мінімальну суму, і поріг ціни.
     * Сума після знижки рахується лише з рядків, на які знижка справді діє.
     */
    public function testMinOrderAndThresholdTogether(): void
    {
        $eligibility = $this->cartEligibility();
        $cart = $this->cart(1200.0, 800.0);
        [$expensive, $cheap] = $cart->purchases;
        // 2000 − 10 % від 1200 = 1880 ≥ 1850. Якби знижку рахували й з 800, вийшло б 1800 < 1850.
        $promo = $this->percent(['min_order_amount' => 1850]);

        self::assertSame(1880.0, $eligibility->cartSubtotalAfterOwnDiscount($promo, $cart));
        self::assertSame($promo, $eligibility->pickBestDiscountCampaignForPurchase($expensive, $cart, [$promo]));
        self::assertNull($eligibility->pickBestDiscountCampaignForPurchase($cheap, $cart, [$promo]));
        self::assertNull($eligibility->pickBestDiscountCampaignForPurchase($expensive, $cart, [$this->percent(['min_order_amount' => 2500])]));
    }

    /** Підказка «застосується при сумі від…» не обіцяє знижку товару нижче порогу. */
    public function testMinOrderNoticeSkipsLinesBelowThreshold(): void
    {
        $campaigns = $this->createStub(PromoCampaignEntity::class);
        $campaigns->method('noLimit')->willReturnSelf();
        $campaigns->method('find')->willReturn([$this->percent(['min_order_amount' => 5000])]);
        $hooks = $this->cartHooks($this->cartEligibility(), $campaigns);
        $cart = $this->cart(1200.0, 800.0);

        $hooks->attachSviatPromoPurchaseDiscounts($cart);

        [$expensive, $cheap] = $cart->purchases;
        self::assertObjectHasProperty('sviat_promo_min_order_notice', $expensive->meta);
        self::assertObjectNotHasProperty('sviat_promo_min_order_notice', $cheap->meta);
    }

    private function cartHooks(PromotionEligibility $eligibility, PromoCampaignEntity $campaigns): PromoCartHooks
    {
        $pipeline = new CartDiscountPipeline(
            $this->mockEntityFactory([CurrenciesEntity::class => $this->mockCurrenciesEntity(2)]),
            $eligibility,
            $this->createStub(DiscountsHelper::class)
        );
        $hooks = new \ReflectionClass(PromoCartHooks::class)->newInstanceWithoutConstructor();
        $factory = $this->mockEntityFactory([PromoCampaignEntity::class => $campaigns]);
        Closure::bind(function () use ($eligibility, $pipeline, $factory) {
            $this->eligibility = $eligibility;
            $this->discountPipeline = $pipeline;
            $this->entityFactory = $factory;
        }, $hooks, PromoCartHooks::class)();

        return $hooks;
    }

    // ── Адмінка замовлення ────────────────────────────────────────────────

    public function testAdminOrderAppliesCampaignOnlyAboveThreshold(): void
    {
        foreach ([1200.0 => 1, 800.0 => 0] as $price => $expectedDiscounts) {
            $products = $this->createStub(ProductsEntity::class);
            $products->method('get')->willReturn((object) ['id' => 5, 'brand_id' => 0, 'main_image_id' => 11, 'main_category_id' => 0]);
            $eligibility = $this->createStub(PromotionEligibility::class);
            $eligibility->method('promoIdsForProduct')->willReturn([7]);
            $eligibility->method('getActiveCampaigns')->willReturn([$this->percent()]);
            $discounts = $this->createMock(DiscountsEntity::class);
            $discounts->expects(self::exactly($expectedDiscounts))->method('add');

            $applier = new AdminOrderPromoApplier($this->mockEntityFactory([
                CurrenciesEntity::class => $this->mockCurrenciesEntity(2),
                ProductsEntity::class => $products,
                DiscountsEntity::class => $discounts,
                PurchasesEntity::class => $this->createStub(PurchasesEntity::class),
                OrdersEntity::class => $this->createStub(OrdersEntity::class),
            ]), $eligibility);
            $applier->rememberAddedPurchase(101, (object) ['product_id' => 5, 'variant_id' => 50, 'amount' => 1, 'undiscounted_price' => (float) $price]);
            $applier->applyPromosToNewPurchases(null, (object) ['id' => 1]);
        }
    }

    // ── Лістинги і лендінг акції ─────────────────────────────────────────

    /** @return list<array{string, int}> */
    public static function listingFilters(): array
    {
        return [
            'лістинг зі знижкою' => ['forDiscounted', 1],
            'лендінг акції' => ['forCampaignScope', 7],
        ];
    }

    /**
     * Сам SQL перевіряють на стенді; тут — що обидва фільтри враховують поріг
     * і порівнюють ціну в основній валюті.
     *
     * @dataProvider listingFilters
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('listingFilters')]
    public function testListingFiltersRespectThreshold(string $method, int $argument): void
    {
        $captured = [];
        $select = $this->createStub(Select::class);
        $select->method('where')->willReturnCallback(function (...$args) use (&$captured) {
            $captured[] = (string) ($args[0] ?? '');
        });
        $filter = new ProductsPromoFilter();
        $filter->setSelect($select);
        $filter->{$method}($argument);
        $sql = implode("\n", $captured);

        self::assertStringContainsString('sp.min_product_price', $sql);
        self::assertStringContainsString('cvt.rate_to / cvt.rate_from', $sql);
    }

    // ── Адмінка акції ─────────────────────────────────────────────────────

    /** @param array<string, mixed> $post */
    private function postPromo(array $post): \stdClass
    {
        $saved = $_POST;
        $_POST = $post;
        try {
            return new CampaignPayloadRequest(new Request())->postPromo();
        } finally {
            $_POST = $saved;
        }
    }

    /**
     * @param array<string, mixed> $post
     * @return array{\stdClass, ?string} акція і перше поле з невалідним числом
     */
    private function postPromoWithErrors(array $post): array
    {
        $saved = $_POST;
        $_POST = $post;
        try {
            $payload = new CampaignPayloadRequest(new Request());

            return [$payload->postPromo(), $payload->invalidDecimalField()];
        } finally {
            $_POST = $saved;
        }
    }

    public function testAdminSaveParsesThreshold(): void
    {
        $base = ['promo_type' => 'percent', 'discount_percent' => '10'];

        self::assertSame(1000.0, $this->postPromo($base + ['min_product_price' => '1000'])->min_product_price);
        self::assertSame(1000.5, $this->postPromo($base + ['min_product_price' => ' 1000,5 '])->min_product_price);
        self::assertSame(0.0, $this->postPromo($base + ['min_product_price' => ''])->min_product_price);
        self::assertSame(0.0, $this->postPromo($base)->min_product_price);
        self::assertSame(0.0, $this->postPromo($base + ['min_product_price' => '0'])->min_product_price);
        self::assertSame(250.0, $this->postPromo(['promo_type' => 'fixed', 'min_product_price' => '250'])->min_product_price);
    }

    /** @return array<string, array{string}> */
    public static function invalidDecimals(): array
    {
        return [
            'мінус' => ['-5'],
            'літери' => ['abc'],
            'експонента' => ['1e3'],
            'пробіл тисяч' => ['10 000'],
            'кома тисяч' => ['1,000'],
            'крапка тисяч' => ['1.000'],
            'два роздільники' => ['1.000,50'],
            'три знаки' => ['12.345'],
            'забагато цифр' => ['12345678901'],
            'лише роздільник' => ['5,'],
        ];
    }

    /**
     * Однаковий формат для всіх числових полів форми: невалідне значення не стає нулем
     * чи «1», а зупиняє збереження з помилкою.
     *
     * @dataProvider invalidDecimals
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDecimals')]
    public function testAdminSaveRejectsInvalidDecimalInEveryMoneyField(string $invalid): void
    {
        $cases = [
            'min_order_amount' => ['promo_type' => 'percent', 'discount_percent' => '10'],
            'min_product_price' => ['promo_type' => 'percent', 'discount_percent' => '10'],
            'discount_fixed' => ['promo_type' => 'fixed'],
            'discount_percent' => ['promo_type' => 'percent'],
        ];
        foreach ($cases as $field => $base) {
            [, $error] = $this->postPromoWithErrors($base + [$field => $invalid]);
            self::assertSame($field, $error, "{$field}: {$invalid}");
        }
        [, $error] = $this->postPromoWithErrors(['promo_type' => 'percent', 'discount_percent' => '10', 'min_order_amount' => ['500']]);
        self::assertSame('min_order_amount', $error, 'масив');
    }

    public function testValidDecimalsPassWithoutError(): void
    {
        foreach (['0', '5', '499,5', '499.50', '1000', '9999999999.99', ' 12,3 '] as $valid) {
            [$promo, $error] = $this->postPromoWithErrors(['promo_type' => 'fixed', 'discount_fixed' => '10', 'min_order_amount' => $valid, 'min_product_price' => $valid]);
            self::assertNull($error, $valid);
            self::assertSame((float) str_replace(',', '.', trim($valid)), $promo->min_order_amount, $valid);
        }
    }

    /** Поріг мають лише відсоткова й фіксована акції; для решти поле не читається. */
    public function testThresholdIsResetForOtherCampaignTypes(): void
    {
        foreach (['gift', 'quantity', 'bundle_3x2', 'free_shipping'] as $type) {
            [$promo, $error] = $this->postPromoWithErrors(['promo_type' => $type, 'min_product_price' => 'abc']);
            self::assertSame(0.0, $promo->min_product_price, $type);
            self::assertNull($error, $type);
            self::assertSame(0.0, $this->postPromo(['promo_type' => $type, 'min_product_price' => '1000'])->min_product_price, $type);
        }
    }

    /** Кома як десятковий роздільник і порожнє поле однаково для всіх грошових полів форми. */
    public function testAdminSaveAcceptsCommaAndEmptyInMoneyFields(): void
    {
        $fixed = $this->postPromo(['promo_type' => 'fixed', 'discount_fixed' => '149,5', 'min_order_amount' => '499,5', 'min_product_price' => '1000,25']);
        self::assertSame(149.5, $fixed->discount_fixed);
        self::assertSame(499.5, $fixed->min_order_amount);
        self::assertSame(1000.25, $fixed->min_product_price);

        $percent = $this->postPromo(['promo_type' => 'percent', 'discount_percent' => '7,5', 'min_order_amount' => '']);
        self::assertSame(7.5, $percent->discount_percent);
        self::assertSame(0.0, $percent->min_order_amount);
        self::assertSame(0.0, $percent->min_product_price);
    }

    /** @return array<string, array{mixed, string}> */
    public static function moneyInputValues(): array
    {
        return [
            'null' => [null, ''],
            'порожньо' => ['', ''],
            'нуль з колонки' => ['0.0000', ''],
            'нуль' => [0, ''],
            'ціле' => ['500.0000', '500'],
            'дріб' => ['499.5000', '499.5'],
            'два знаки' => ['12.3400', '12.34'],
            'округлення до двох' => ['0.4567', '0.46'],
            'тисячі без пробілів' => ['12500.0000', '12500'],
            'не число' => ['abc', ''],
        ];
    }

    /**
     * @dataProvider moneyInputValues
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('moneyInputValues')]
    public function testMoneyInputModifierTrimsDecimalTail(mixed $value, string $expected): void
    {
        self::assertSame($expected, new MoneyInputModifier()->run($value));
    }

    /** @param array<string, mixed> $params */
    private static function renderMoneyInput(array $params): string
    {
        $root = dirname(__DIR__, 4);
        // Сток 4.5.3 має Smarty 4 (клас \Smarty), форк — Smarty 5 (\Smarty\Smarty); потрібні методи однакові.
        $class = class_exists('Smarty\\Smarty') ? 'Smarty\\Smarty' : 'Smarty';
        $smarty = new $class();
        $smarty->setTemplateDir([$root . '/Okay/Modules/Sviat/Promo/Backend/design/html']);
        $smarty->setCompileDir(sys_get_temp_dir() . '/promo-money-input-' . getmypid());
        $smarty->registerPlugin('modifier', 'sviat_promo_money_input', [new MoneyInputModifier(), 'run']);
        $smarty->assign($params + ['class' => '', 'min' => '0', 'max' => '', 'placeholder' => '0']);

        return trim($smarty->fetch('campaign_money_input.tpl'));
    }

    /** Справжній рендер поля форми: значення з колонки DECIMAL без нулів у хвості, з екрануванням. */
    public function testMoneyInputTemplateRendersTrimmedValue(): void
    {
        $html = self::renderMoneyInput(['name' => 'min_order_amount', 'value' => '500.0000']);
        self::assertSame('<input class="form-control" name="min_order_amount" type="number" min="0" step="0.01" value="500" placeholder="0"/>', $html);

        self::assertStringContainsString('value="" placeholder="0"', self::renderMoneyInput(['name' => 'min_product_price', 'value' => '0.0000']));
        self::assertStringContainsString('value="499.5"', self::renderMoneyInput(['name' => 'discount_fixed', 'value' => '499.5000', 'class' => 'fn_discount_fixed', 'min' => '0.01']));

        $percent = self::renderMoneyInput(['name' => 'discount_percent', 'value' => '10.00', 'class' => 'fn_discount_percent', 'min' => '1', 'max' => '100', 'placeholder' => '%']);
        self::assertSame('<input class="form-control fn_discount_percent" name="discount_percent" type="number" min="1" max="100" step="0.01" value="10" placeholder="%"/>', $percent);

        self::assertStringContainsString('placeholder="&quot;&gt;"', self::renderMoneyInput(['name' => 'x', 'value' => null, 'placeholder' => '">']));
    }

    /** Усі чотири числові поля форми рендеряться через цей шаблон. */
    public function testCampaignFormUsesMoneyInputForEveryNumberField(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 4) . '/Okay/Modules/Sviat/Promo/Backend/design/html/campaign_edit.tpl');
        foreach (['discount_percent', 'discount_fixed', 'min_order_amount', 'min_product_price'] as $field) {
            self::assertMatchesRegularExpression("/\\{include file='campaign_money_input\\.tpl' name='{$field}'[^}]* value=\\\$promo->{$field} /", $template, $field);
        }
        self::assertDoesNotMatchRegularExpression('/\$promo->(min_order_amount|min_product_price|discount_fixed|discount_percent)\|/', $template);

        $registered = include dirname(__DIR__, 4) . '/Okay/Modules/Sviat/Promo/Init/SmartyPlugins.php';
        self::assertArrayHasKey(MoneyInputModifier::class, $registered);
    }

    public function testAdminSaveShowsErrorForInvalidDecimal(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 4) . '/Okay/Modules/Sviat/Promo/Backend/Controllers/CampaignEditAdmin.php');
        $template = (string) file_get_contents(dirname(__DIR__, 4) . '/Okay/Modules/Sviat/Promo/Backend/design/html/campaign_edit.tpl');

        self::assertMatchesRegularExpression('/elseif \(\(\$invalidField = \$payload->invalidDecimalField\(\)\) !== null\) \{\s*\$this->design->assign\(\'message_error\', \'invalid_decimal\'\);/', $controller);
        // Перевірка формату стоїть до «вкажіть знижку»: інакше «abc» у знижці дало б не ту помилку.
        self::assertLessThan(strpos($controller, "'empty_discount_percent'"), strpos($controller, "'invalid_decimal'"));
        self::assertStringContainsString("{elseif \$message_error == 'invalid_decimal'}", $template);
        self::assertStringContainsString('{$btr->getTranslation($invalid_decimal_label)|escape}: {$btr->sviat_promo__invalid_decimal|escape}', $template);
        self::assertStringContainsString('{$btr->sviat_promo__min_product_price_hint|escape}', $template);
    }

    public function testTranslationsExistInAllThreeLanguages(): void
    {
        foreach (['ua', 'en', 'ru'] as $code) {
            $lang = [];
            include dirname(__DIR__, 4) . "/Okay/Modules/Sviat/Promo/Backend/lang/{$code}.php";
            foreach (['sviat_promo__min_product_price', 'sviat_promo__min_product_price_hint', 'sviat_promo__invalid_decimal', 'sviat_promo__min_order', 'sviat_promo__discount_fixed', 'sviat_promo__discount_percent'] as $key) {
                self::assertNotSame('', $lang[$key] ?? '', "{$code}: {$key}");
            }
        }
        $lang = [];
        include dirname(__DIR__, 4) . '/Okay/Modules/Sviat/Promo/Backend/lang/ua.php';
        self::assertSame("невід'ємне число, до 2 знаків після коми, без пробілів і розділювачів тисяч", $lang['sviat_promo__invalid_decimal']);
    }

    // ── Міграція ──────────────────────────────────────────────────────────

    /**
     * update_2_1_0 через справжній EntityMigrator: перший запуск додає колонку,
     * повторний нічого не змінює. Таблицю імітує Database, що відповідає на SHOW COLUMNS.
     */
    public function testMigrationAddsColumnOnceAndIsIdempotent(): void
    {
        $columns = [];
        $statements = [];
        $db = $this->createStub(Database::class);
        $db->method('query')->willReturnCallback(function (SqlQuery $query) use (&$columns, &$statements): bool {
            $sql = (string) $query->getStatement();
            if (str_starts_with($sql, 'SHOW COLUMNS')) {
                return true;
            }
            $statements[] = $sql;
            if (preg_match('/ADD COLUMN `(\w+)` (\S+)/', $sql, $m)) {
                $columns[$m[1]] = (object) ['Field' => $m[1], 'Type' => $m[2], 'Null' => 'NO', 'Default' => '0.0000'];
            }

            return true;
        });
        $db->method('results')->willReturnCallback(function () use (&$columns): array {
            return array_values($columns);
        });
        $queryFactory = $this->createStub(QueryFactory::class);
        $queryFactory->method('newSqlQuery')->willReturnCallback(static fn(): SqlQuery => new SqlQuery());
        // Деструктор Database від'єднує PDO, якого в заглушки немає.
        Closure::bind(fn() => $this->pdo = new class {
            public function disconnect(): void {}
        }, $db, Database::class)();
        $migrator = new EntityMigrator($db, $queryFactory, new SqlPresentor());

        $init = new \ReflectionClass(Init::class)->newInstanceWithoutConstructor();
        Closure::bind(fn() => $this->entityMigrator = $migrator, $init, AbstractInit::class)();

        $init->update_2_1_0();
        self::assertCount(1, $statements);
        self::assertMatchesRegularExpression("/ADD COLUMN `min_product_price` decimal\\(14,4\\)\\s+NOT NULL\\s+DEFAULT '0'/", $statements[0]);
        self::assertStringContainsString('`' . PromoCampaignEntity::getTable() . '`', $statements[0]);

        $init->update_2_1_0();
        self::assertCount(1, $statements, 'повторний запуск не має змінювати таблицю');
    }

    /** Чиста інсталяція і оновлення створюють однакову колонку. */
    public function testInstallAndUpdateShareFieldDefinition(): void
    {
        $init = (string) file_get_contents(new \ReflectionClass(Init::class)->getFileName());

        self::assertSame(2, substr_count($init, 'self::minProductPriceField()'));
        self::assertContains('min_product_price', self::accessible(new \ReflectionProperty(PromoCampaignEntity::class, 'fields'))->getValue());
    }
}
