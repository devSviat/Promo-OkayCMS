<?php

namespace Okay\Modules\Sviat\Promo\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Redis\Services\CacheTags;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

/**
 * Invalidate Redis caches when promo campaign or scope changes.
 * Why: PromoProductDisplayService bakes the discounted price into cached product
 * lists/cards. Re-firing the chain extender on cache hit is a no-op because the
 * service is idempotent (skips when sviat_promo_price_display_applied is set).
 * The only reliable invalidation is bumping the Redis tags that those cache
 * keys depend on.
 */
class PromoCampaignCacheInvalidator implements ExtensionInterface
{
    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    public function onCampaignAdd($output, $object): void
    {
        if ((int) $output > 0) { $this->bumpAll(); }
    }

    public function onCampaignUpdate($output, $ids, $object): void
    {
        if ($output) { $this->bumpAll(); }
    }

    public function onCampaignDelete($output, $ids): void
    {
        if ($output) { $this->bumpAll(); }
    }

    public function onScopeAdd($output, $object): void
    {
        if ((int) $output > 0) { $this->bumpAll(); }
    }

    public function onScopeUpdate($output, $ids, $object): void
    {
        if ($output) { $this->bumpAll(); }
    }

    public function onScopeDelete($output, $ids): void
    {
        if ($output) { $this->bumpAll(); }
    }

    private function bumpAll(): void
    {
        // Discount/eligibility may affect every product card and every list.
        $this->redis->bump(CacheTags::PRODUCTS_ALL);
        $this->redis->bump(CacheTags::PRODUCTS_LIST);
    }
}
