<?php

namespace Okay\Modules\Sviat\Promo\Entities;

use Okay\Core\Entity\Entity;

class PromoFeedLinkEntity extends Entity
{
    public const TYPE_FEEDS = 'feeds';
    public const TYPE_GM    = 'gm';

    protected static $fields = [
        'promo_id',
        'feed_type',
        'feed_id',
    ];

    protected static $defaultOrderFields = ['promo_id'];
    protected static $table      = 'sviat__promo_feed_links';
    protected static $tableAlias = 'spfl';

    public function deleteByPromoId(int $promoId): void
    {
        $delete = $this->queryFactory->newDelete();
        $delete->from(self::getTable())
            ->where('promo_id = :pid')
            ->bindValue('pid', $promoId)
            ->execute();
    }

    /**
     * @return array<string, int[]> feed_type => [feed_id, ...]
     */
    public function getLinkedFeedIds(int $promoId): array
    {
        $rows   = $this->find(['promo_id' => $promoId]);
        $result = [];
        foreach ($rows as $row) {
            $result[$row->feed_type][] = (int) $row->feed_id;
        }
        return $result;
    }

    /**
     * @param int[] $promoIds
     * @return array<int, array<string, int[]>> promo_id => feed_type => [feed_id, ...]
     */
    public function getLinkedFeedsGrouped(array $promoIds): array
    {
        if (empty($promoIds)) {
            return [];
        }
        $rows   = $this->find(['promo_id' => $promoIds]);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->promo_id][$row->feed_type][] = (int) $row->feed_id;
        }
        return $result;
    }
}
