<?php

namespace Modules\Sviat\Promo;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Services\ProductCacheInvalidation;
use Okay\Modules\Sviat\Promo\Services\PromoBoundaryWatcher;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;
use PHPUnit\Framework\TestCase;

/**
 * Кампанія вмикається й вимикається за годинником, без запису в базу, тож
 * інвалідатор сутності цього переходу не бачить — його ловить планувальник.
 */
class PromoBoundaryWatcherTest extends TestCase
{
    /** @param array<int, object> $campaigns */
    private function watcher(
        array $campaigns,
        FakeInvalidation $cache,
        FakeSettings $settings,
        callable $isActive
    ): PromoBoundaryWatcher {
        $entity = $this->createStub(PromoCampaignEntity::class);
        $entity->method('noLimit')->willReturnSelf();
        $entity->method('find')->willReturn($campaigns);

        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);

        $eligibility = $this->createStub(PromotionEligibility::class);
        $eligibility->method('campaignVisibleOnStorefront')->willReturn(true);
        $eligibility->method('campaignDatesOk')->willReturnCallback($isActive);

        return new PromoBoundaryWatcher($factory, $eligibility, $settings, $cache);
    }

    public function testFirstRunRecordsTheActiveSet(): void
    {
        $cache = new FakeInvalidation();
        $settings = new FakeSettings();

        $this->watcher([(object) ['id' => 7]], $cache, $settings, static fn() => true)
            ->invalidateWhenActiveSetChanges();

        $this->assertSame('7', $settings->stored['sviat__promo__active_set'] ?? null);
    }

    public function testUnchangedSetDoesNotTouchTheCache(): void
    {
        $cache = new FakeInvalidation();
        $settings = new FakeSettings(['sviat__promo__active_set' => '7']);

        $this->watcher([(object) ['id' => 7]], $cache, $settings, static fn() => true)
            ->invalidateWhenActiveSetChanges();

        $this->assertSame(0, $cache->calls, 'щохвилинна задача не має скидати кеш просто так');
    }

    public function testCampaignFallingOutOfItsWindowInvalidates(): void
    {
        $cache = new FakeInvalidation();
        $settings = new FakeSettings(['sviat__promo__active_set' => '7']);

        $this->watcher([(object) ['id' => 7]], $cache, $settings, static fn() => false)
            ->invalidateWhenActiveSetChanges();

        $this->assertSame(1, $cache->calls);
        $this->assertSame('', $settings->stored['sviat__promo__active_set']);
    }

    public function testOrderOfCampaignsDoesNotCountAsAChange(): void
    {
        $cache = new FakeInvalidation();
        $settings = new FakeSettings(['sviat__promo__active_set' => '3,7']);

        $this->watcher([(object) ['id' => 7], (object) ['id' => 3]], $cache, $settings, static fn() => true)
            ->invalidateWhenActiveSetChanges();

        $this->assertSame(0, $cache->calls, 'той самий набір у іншому порядку — не зміна');
    }

    /** Без кешу задача має мовчати, а не падати щохвилини в планувальнику. */
    public function testWithoutACacheNothingHappens(): void
    {
        $cache = new FakeInvalidation(false);
        $settings = new FakeSettings();

        $this->watcher([(object) ['id' => 7]], $cache, $settings, static fn() => true)
            ->invalidateWhenActiveSetChanges();

        $this->assertSame(0, $cache->calls);
        $this->assertSame([], $settings->stored);
    }
}

class FakeInvalidation implements ProductCacheInvalidation
{
    public int $calls = 0;

    public function __construct(private bool $available = true)
    {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function invalidateProductData(): void
    {
        $this->calls++;
    }
}

class FakeSettings extends Settings
{
    /** @var array<string, string> */
    public array $stored;

    public function __construct(array $stored = [])
    {
        $this->stored = $stored;
    }

    public function get($param)
    {
        return $this->stored[$param] ?? null;
    }

    public function set($param, $value)
    {
        $this->stored[$param] = (string) $value;
    }
}
