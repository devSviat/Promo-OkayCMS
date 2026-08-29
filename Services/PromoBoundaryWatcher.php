<?php

namespace Okay\Modules\Sviat\Promo\Services;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;

/**
 * Акція вмикається й вимикається сама, за датою, без жодного запису в базу —
 * тобто перехід не бачить ні інвалідатор сутності, ні будь-що інше. Тут його
 * помічає планувальник.
 *
 * Порівнюємо не час, а склад активного набору: так перевірка переживає
 * пропущений тік і не залежить від того, коли саме її запустили.
 */
class PromoBoundaryWatcher
{
    private const STATE_PARAM = 'sviat__promo__active_set';

    private EntityFactory $entityFactory;
    private PromotionEligibility $eligibility;
    private Settings $settings;
    private ProductCacheInvalidation $cache;

    public function __construct(
        EntityFactory $entityFactory,
        PromotionEligibility $eligibility,
        Settings $settings,
        ProductCacheInvalidation $cache
    ) {
        $this->entityFactory = $entityFactory;
        $this->eligibility = $eligibility;
        $this->settings = $settings;
        $this->cache = $cache;
    }

    public function invalidateWhenActiveSetChanges(): void
    {
        if (!$this->cache->isAvailable()) {
            return;
        }

        $current = $this->activeSetSignature();
        if ($current === (string) $this->settings->get(self::STATE_PARAM)) {
            return;
        }

        $this->settings->set(self::STATE_PARAM, $current);
        $this->cache->invalidateProductData();
    }

    /**
     * Ознака активності — та сама, за якою вітрина вирішує показувати акцію,
     * а не власне тлумачення дат.
     */
    private function activeSetSignature(): string
    {
        /** @var PromoCampaignEntity $campaigns */
        $campaigns = $this->entityFactory->get(PromoCampaignEntity::class);

        $active = [];
        foreach ($campaigns->noLimit()->find() as $campaign) {
            if ($this->eligibility->campaignVisibleOnStorefront($campaign)
                && $this->eligibility->campaignDatesOk($campaign)
            ) {
                $active[] = (int) $campaign->id;
            }
        }
        sort($active);

        return implode(',', $active);
    }
}
