<?php

namespace Okay\Modules\Sviat\Promo\Entities;

use Okay\Core\Entity\Entity;

/** Пороги акції «за кількістю»: від min_quantity одиниць діє percent. */
class PromoQuantityTierEntity extends Entity
{
    protected static $fields = ['id', 'promo_id', 'min_quantity', 'percent'];
    protected static $defaultOrderFields = ['min_quantity'];
    protected static $table = 'sviat__promo_quantity_tiers';
    protected static $tableAlias = 'spqt';

    public function deleteByPromoId(int $promoId): void
    {
        $this->queryFactory->newDelete()
            ->from(self::getTable())
            ->where('promo_id = :promo_id')
            ->bindValue('promo_id', $promoId)
            ->execute();
    }
}
