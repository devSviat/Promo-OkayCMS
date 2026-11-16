<?php

namespace Modules\Sviat\Promo;

use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\Select;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoFeedLinkEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoScopeEntity;
use Okay\Modules\Sviat\Promo\Services\PromoFeedPriceResolver;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;

require_once __DIR__ . '/PromoTestCase.php';

class PromoFeedPriceResolverTest extends PromoTestCase
{
    private function makeResolver(int $cents = 2): PromoFeedPriceResolver
    {
        $eligibility = $this->createStub(PromotionEligibility::class);
        $queryFactory = $this->createStub(QueryFactory::class);
        $entityFactory = $this->mockEntityFactory([
            CurrenciesEntity::class => $this->mockCurrenciesEntity($cents),
        ]);
        return new PromoFeedPriceResolver($entityFactory, $queryFactory, $eligibility);
    }

    public function testComputePromoPricePercentRoundsToCurrencyPrecision(): void
    {
        $resolver = $this->makeResolver(0);
        $promo = $this->buildPromo(['promo_type' => PromoCampaignEntity::TYPE_PERCENT, 'discount_percent' => 25]);

        self::assertSame(150.0, $resolver->computePromoPrice($promo, 199.99));
    }

    public function testComputePromoPricePercentRoundsToTwoDecimals(): void
    {
        $resolver = $this->makeResolver(2);
        $promo = $this->buildPromo(['promo_type' => PromoCampaignEntity::TYPE_PERCENT, 'discount_percent' => 25]);

        self::assertSame(149.99, $resolver->computePromoPrice($promo, 199.99));
    }

    public function testComputePromoPriceFixed(): void
    {
        $resolver = $this->makeResolver(0);
        $promo = $this->buildPromo(['promo_type' => PromoCampaignEntity::TYPE_FIXED, 'discount_fixed' => 50]);

        self::assertSame(150.0, $resolver->computePromoPrice($promo, 199.99));
    }

    public function testComputePromoPriceReturnsNullForInvalidPercent(): void
    {
        $resolver = $this->makeResolver();
        foreach ([0, -5, 101] as $invalidPct) {
            $promo = $this->buildPromo(['promo_type' => PromoCampaignEntity::TYPE_PERCENT, 'discount_percent' => $invalidPct]);
            self::assertNull($resolver->computePromoPrice($promo, 100.0), 'pct=' . $invalidPct);
        }
    }

    public function testComputePromoPriceReturnsNullForZeroBase(): void
    {
        $resolver = $this->makeResolver();
        $promo = $this->buildPromo();
        self::assertNull($resolver->computePromoPrice($promo, 0.0));
        self::assertNull($resolver->computePromoPrice($promo, -10.0));
    }

    public function testComputePromoPriceReturnsNullWhenFixedExceedsBase(): void
    {
        $resolver = $this->makeResolver();
        $promo = $this->buildPromo(['promo_type' => PromoCampaignEntity::TYPE_FIXED, 'discount_fixed' => 200]);
        self::assertNull($resolver->computePromoPrice($promo, 199.0));
    }

    public function testBuildSalePriceDateRangeFormatsUtcInterval(): void
    {
        $resolver = $this->makeResolver();
        $range = $resolver->buildSalePriceDateRange('2026-05-01 00:00:00', '2026-05-10 12:00:00');

        self::assertIsString($range);
        self::assertMatchesRegularExpression('#^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$#', $range);
    }

    /**
     * Без дати початку старт — початок поточної доби (UTC), а не поточна секунда: sale_price_effective_date
     * інакше мінявся б на кожній збірці, і модуль Merchant API надсилав би такі товари щоразу заново.
     */
    public function testRangeWithoutStartIsStableWithinTheDay(): void
    {
        $resolver = $this->makeResolver();
        $range = $resolver->buildSalePriceDateRange(null, '2099-12-31 23:59:00');

        self::assertSame(gmdate('Y-m-d') . 'T00:00:00Z/2099-12-31', substr((string) $range, 0, 31));
        sleep(1);
        self::assertSame($range, $resolver->buildSalePriceDateRange('', '2099-12-31 23:59:00'));
    }

    public function testBuildSalePriceDateRangeReturnsNullOnInvalidEnd(): void
    {
        $resolver = $this->makeResolver();
        self::assertNull($resolver->buildSalePriceDateRange('2026-05-01', 'not-a-date'));
    }

    public function testFindBestCampaignSkipsImagelessProductWhenFlagOn(): void
    {
        // Smoke check: we verify the exclude_no_image early-return in findBestCampaign
        // by reflectively setting the resolver's internal state, since preload() and
        // setCurrentFeed() depend on entities/queries we don't want to stub here.
        $resolver = $this->makeResolver(2);

        $promo = $this->buildPromo([
            'id' => 7,
            'exclude_no_image' => 1,
            'position' => 0,
        ]);

        $ref = new \ReflectionClass($resolver);

        $feedType = self::accessible($ref->getProperty('currentFeedType'));        $feedType->setValue($resolver, 'feeds');
        $feedId = self::accessible($ref->getProperty('currentFeedId'));        $feedId->setValue($resolver, 99);

        $active = self::accessible($ref->getProperty('activeCampaigns'));        $active->setValue($resolver, [7 => $promo]);
        $links = self::accessible($ref->getProperty('feedLinksByCampaign'));        $links->setValue($resolver, [7 => ['feeds' => [99]]]);
        $scopes = self::accessible($ref->getProperty('scopesByCampaign'));        $scopes->setValue($resolver, [7 => [
            'inclusions' => ['has_rows' => true, 'products' => [1 => true], 'brands' => [], 'categories' => [], 'feature_groups' => []],
            'exclusions' => ['has_rows' => false, 'products' => [], 'brands' => [], 'categories' => [], 'feature_groups' => []],
        ]]);

        // Image-less product is skipped, with-image one is returned.
        self::assertNull($resolver->findBestCampaign(1, 0, 0, 0, 100.0));
        self::assertSame(7, (int) $resolver->findBestCampaign(1, 0, 0, 11, 100.0)->id);
    }

    /** Резолвер фіду Feeds 99 з уже завантаженими кампаніями на товар 1. */
    private function loadedResolver(object ...$campaigns): PromoFeedPriceResolver
    {
        $resolver = $this->makeResolver(2);
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
        \Closure::bind(function () use ($active, $links, $scopes) {
            $this->currentFeedType = 'feeds';
            $this->currentFeedId = 99;
            $this->activeCampaigns = $active;
            $this->feedLinksByCampaign = $links;
            $this->scopesByCampaign = $scopes;
        }, $resolver, PromoFeedPriceResolver::class)();

        return $resolver;
    }

    private function minOrderPromo(array $overrides = []): \stdClass
    {
        return $this->buildPromo(array_merge(['id' => 7, 'feed_enabled' => 1, 'discount_percent' => 10, 'min_order_amount' => 1000], $overrides));
    }

    /** Фід має збігатися з ціною на сайті: гарантований варіант — зі знижкою, умовний — без. */
    public function testFindBestCampaignIncludesGuaranteedAndExcludesConditional(): void
    {
        $resolver = $this->loadedResolver($this->minOrderPromo());

        self::assertSame(7, (int) $resolver->findBestCampaign(1, 0, 0, 11, 1200.0)?->id);
        self::assertNull($resolver->findBestCampaign(1, 0, 0, 11, 1000.0));
        self::assertNull($resolver->findBestCampaign(1, 0, 0, 11, 1111.0), '999.9 < 1000');
    }

    public function testFixedMinOrderCampaignInFeed(): void
    {
        $resolver = $this->loadedResolver($this->minOrderPromo(['promo_type' => PromoCampaignEntity::TYPE_FIXED, 'discount_percent' => null, 'discount_fixed' => 300]));

        self::assertSame(7, (int) $resolver->findBestCampaign(1, 0, 0, 11, 1300.0)?->id);
        self::assertNull($resolver->findBestCampaign(1, 0, 0, 11, 1299.0));
    }

    /** Як у кошику з однією одиницею: умовна акція поступається наступній за пріоритетом. */
    public function testConditionalCampaignYieldsToNextInFeed(): void
    {
        $resolver = $this->loadedResolver(
            $this->minOrderPromo(['discount_percent' => 20]),
            $this->buildPromo(['id' => 9, 'position' => 1, 'feed_enabled' => 1, 'discount_percent' => 5])
        );

        self::assertSame(9, (int) $resolver->findBestCampaign(1, 0, 0, 11, 1000.0)?->id);
        self::assertSame(7, (int) $resolver->findBestCampaign(1, 0, 0, 11, 1500.0)?->id);
    }

    /** preload() більше не відкидає кампанії з мінімальною сумою — рішення приймається на кожен варіант. */
    public function testPreloadKeepsMinOrderCampaigns(): void
    {
        $campaigns = $this->createStub(PromoCampaignEntity::class);
        $campaigns->method('noLimit')->willReturnSelf();
        $campaigns->method('find')->willReturn([$this->minOrderPromo()]);
        $scopes = $this->createStub(PromoScopeEntity::class);
        $scopes->method('noLimit')->willReturnSelf();
        $scopes->method('find')->willReturn([(object) ['promo_id' => 7, 'type' => 'product', 'object_id' => 1, 'exclude' => 0]]);
        $links = $this->createStub(PromoFeedLinkEntity::class);
        $links->method('getLinkedFeedsGrouped')->willReturn([7 => ['feeds' => [99]]]);
        $currencies = $this->createStub(CurrenciesEntity::class);
        $currencies->method('getMainCurrency')->willReturn((object) ['id' => 1, 'cents' => 2]);
        $currencies->method('find')->willReturn([]);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('cols')->willReturnSelf();
        $select->method('results')->willReturn([]);
        $queryFactory = $this->createStub(QueryFactory::class);
        $queryFactory->method('newSelect')->willReturn($select);
        $eligibility = $this->createStub(PromotionEligibility::class);
        $eligibility->method('campaignDatesOk')->willReturn(true);
        $resolver = new PromoFeedPriceResolver(
            $this->mockEntityFactory([
                CurrenciesEntity::class => $currencies,
                PromoCampaignEntity::class => $campaigns,
                PromoScopeEntity::class => $scopes,
                PromoFeedLinkEntity::class => $links,
            ]),
            $queryFactory,
            $eligibility
        );
        \Closure::bind(function () {
            $this->currentFeedType = 'feeds';
            $this->currentFeedId = 99;
        }, $resolver, PromoFeedPriceResolver::class)();

        $resolver->preload();

        self::assertSame(7, (int) $resolver->findBestCampaign(1, 0, 0, 11, 1200.0)?->id);
        self::assertNull($resolver->findBestCampaign(1, 0, 0, 11, 1000.0));
    }
}
