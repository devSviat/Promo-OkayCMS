<?php

namespace Modules\Sviat\Promo;

use Okay\Core\QueryFactory;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Services\PromoFeedPriceResolver;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;

require_once __DIR__ . '/PromoTestCase.php';

class PromoFeedPriceResolverTest extends PromoTestCase
{
    private function makeResolver(int $cents = 2): PromoFeedPriceResolver
    {
        $eligibility = $this->createMock(PromotionEligibility::class);
        $queryFactory = $this->createMock(QueryFactory::class);
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

        $feedType = $ref->getProperty('currentFeedType');
        if (PHP_VERSION_ID < 80100) { $feedType->setAccessible(true); }        $feedType->setValue($resolver, 'feeds');
        $feedId = $ref->getProperty('currentFeedId');
        if (PHP_VERSION_ID < 80100) { $feedId->setAccessible(true); }        $feedId->setValue($resolver, 99);

        $active = $ref->getProperty('activeCampaigns');
        if (PHP_VERSION_ID < 80100) { $active->setAccessible(true); }        $active->setValue($resolver, [7 => $promo]);
        $links = $ref->getProperty('feedLinksByCampaign');
        if (PHP_VERSION_ID < 80100) { $links->setAccessible(true); }        $links->setValue($resolver, [7 => ['feeds' => [99]]]);
        $scopes = $ref->getProperty('scopesByCampaign');
        if (PHP_VERSION_ID < 80100) { $scopes->setAccessible(true); }        $scopes->setValue($resolver, [7 => [
            'inclusions' => ['has_rows' => true, 'products' => [1 => true], 'brands' => [], 'categories' => [], 'feature_groups' => []],
            'exclusions' => ['has_rows' => false, 'products' => [], 'brands' => [], 'categories' => [], 'feature_groups' => []],
        ]]);

        // Image-less product is skipped, with-image one is returned.
        self::assertNull($resolver->findBestCampaign(1, 0, 0, 0));
        self::assertSame(7, (int) $resolver->findBestCampaign(1, 0, 0, 11)->id);
    }
}