<?php

namespace Okay\Modules\Sviat\Promo\Entities;

use Okay\Core\Entity\Entity;

class PromoRewardLineEntity extends Entity
{
    protected static $fields = [
        'promo_id',
        'gift_id',
        'position',
    ];

    protected static $defaultOrderFields = [
        'position',
    ];

    protected static $table = 'sviat__promo_gift';
    protected static $tableAlias = 'spg';

    public function find(array $filter = [])
    {
        if (isset($filter['visible'])) {
            $this->select->join('LEFT', PromoCampaignEntity::getTable() . ' AS sp', 'sp.id=spg.promo_id');
            $this->select->where('sp.visible = 1');
        }

        return parent::find($filter);
    }

    public function removeGiftsByPromo($promoId)
    {
        $delete = $this->queryFactory->newDelete();
        $delete->from($this->getTable())
            ->where('promo_id = :pid')
            ->bindValue('pid', $promoId)
            ->execute();

        return null;
    }
}
