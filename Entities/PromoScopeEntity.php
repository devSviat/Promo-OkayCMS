<?php

namespace Okay\Modules\Sviat\Promo\Entities;

use Okay\Core\Entity\Entity;

class PromoScopeEntity extends Entity
{
    protected static $fields = [
        'promo_id',
        'object_id',
        'type',
        'feature_id',
        'exclude',  // 0 = включення, 1 = виключення
    ];

    protected static $defaultOrderFields = [
        'promo_id',
    ];

    protected static $table = 'sviat__promo_object';
    protected static $tableAlias = 'spo';

    public function deleteByPromoId($promoId)
    {
        $delete = $this->queryFactory->newDelete();
        $delete->from($this->getTable())
            ->where('promo_id = :pid')
            ->bindValue('pid', $promoId)
            ->execute();

        return null;
    }

    /**
     * Повертає promo_id, де товар підпадає під рядки включення (exclude=0).
     *
     * @param array<int, int> $categoryIds
     * @return array<int, int>
     */
    public function findPromoIdsForProduct(int $productId, int $brandId, array $categoryIds): array
    {
        return $this->findPromoIdsByExcludeFlag($productId, $brandId, $categoryIds, 0);
    }

    /**
     * Повертає promo_id, де товар підпадає під рядки виключення (exclude=1).
     *
     * @param array<int, int> $categoryIds
     * @return array<int, int>
     */
    public function findExclusionPromoIdsForProduct(int $productId, int $brandId, array $categoryIds): array
    {
        return $this->findPromoIdsByExcludeFlag($productId, $brandId, $categoryIds, 1);
    }

    private function findPromoIdsByExcludeFlag(int $productId, int $brandId, array $categoryIds, int $excludeFlag): array
    {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));

        $regularIds = $this->findRegularTypePromoIds($productId, $brandId, $categoryIds, $excludeFlag);

        $featureIds = $excludeFlag === 0
            ? $this->findFeatureValueInclusionPromoIds($productId)
            : $this->findFeatureValueExclusionPromoIds($productId);

        return array_values(array_unique(array_merge($regularIds, $featureIds)));
    }

    // $excludeFlag: 0 = inclusion rows, 1 = exclusion rows
    private function findRegularTypePromoIds(int $productId, int $brandId, array $categoryIds, int $excludeFlag): array
    {
        $select = $this->queryFactory->newSelect();
        $select->cols(['spo.promo_id'])->distinct()->from(self::getTable() . ' spo');

        $parts = ['(spo.type = \'product\' AND spo.object_id = :sv_promo_pid)'];
        $select->bindValue('sv_promo_pid', $productId);
        if ($brandId > 0) {
            $parts[] = '(spo.type = \'brand\' AND spo.object_id = :sv_promo_bid)';
            $select->bindValue('sv_promo_bid', $brandId);
        }
        if ($categoryIds !== []) {
            $parts[] = '(spo.type = \'category\' AND spo.object_id IN (:sv_promo_cids))';
            $select->bindValue('sv_promo_cids', $categoryIds);
        }

        $select->where('(' . implode(' OR ', $parts) . ')')
               ->where('spo.exclude = :sv_excl_flag')
               ->bindValue('sv_excl_flag', $excludeFlag);

        $this->db->query($select);
        $ids = [];
        foreach ($this->db->results() as $row) {
            $ids[] = (int) $row->promo_id;
        }

        return array_values(array_unique($ids));
    }

    private function findFeatureValueInclusionPromoIds(int $productId): array
    {
        $sql = $this->queryFactory->newSqlQuery();
        $sql->setStatement("
            SELECT spo.promo_id
            FROM __sviat__promo_object spo
            INNER JOIN __features_values fv ON fv.id = spo.object_id
            WHERE spo.type = 'feature_value' AND spo.exclude = 0
              AND spo.object_id IN (
                  SELECT pfv.value_id FROM __products_features_values pfv WHERE pfv.product_id = :sv_fv_pid
              )
            GROUP BY spo.promo_id
            HAVING COUNT(DISTINCT fv.feature_id) = (
                SELECT COUNT(DISTINCT fv2.feature_id)
                FROM __sviat__promo_object spo2
                INNER JOIN __features_values fv2 ON fv2.id = spo2.object_id
                WHERE spo2.promo_id = spo.promo_id AND spo2.type = 'feature_value' AND spo2.exclude = 0
            )
        ");
        $sql->bindValue('sv_fv_pid', $productId);
        $this->db->query($sql);
        $ids = [];
        foreach ($this->db->results() as $row) {
            $ids[] = (int) $row->promo_id;
        }

        return array_values(array_unique($ids));
    }

    private function findFeatureValueExclusionPromoIds(int $productId): array
    {
        $sql = $this->queryFactory->newSqlQuery();
        $sql->setStatement("
            SELECT DISTINCT spo.promo_id
            FROM __sviat__promo_object spo
            WHERE spo.type = 'feature_value' AND spo.exclude = 1
              AND spo.object_id IN (
                  SELECT pfv.value_id FROM __products_features_values pfv WHERE pfv.product_id = :sv_fv_ex_pid
              )
        ");
        $sql->bindValue('sv_fv_ex_pid', $productId);
        $this->db->query($sql);
        $ids = [];
        foreach ($this->db->results() as $row) {
            $ids[] = (int) $row->promo_id;
        }

        return array_values(array_unique($ids));
    }
}
