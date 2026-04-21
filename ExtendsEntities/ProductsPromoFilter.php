<?php

namespace Okay\Modules\Sviat\Promo\ExtendsEntities;

use Okay\Core\Modules\AbstractModuleEntityFilter;

/**
 * Обмежує вибірку товарів тими, що входять у область дії кампанії (категорія / бренд / SKU).
 */
class ProductsPromoFilter extends AbstractModuleEntityFilter
{
    /**
     * SQL-умова "товар зі знижкою": стандартна compare_price або активна
     * акція Promo (відсоткова/фіксована).
     *
     * Логіка скопу: товар включений, якщо:
     * - Якщо обрано товари: товар у списку товарів
     * - Якщо обрано категорії: товар у однієї з них
     * - Якщо обрано бренди: товар одного з них
     * - І між типами (якщо обрано більше одного типу)
     * - І товар не у виключеннях
     */
    private function discountedWhereSql(): string
    {
        return '(
            EXISTS (
                SELECT 1
                FROM __variants pv
                WHERE pv.product_id = p.id
                  AND pv.compare_price > pv.price
                LIMIT 1
            )
            OR EXISTS (
                SELECT 1
                FROM __sviat__promos sp
                WHERE sp.visible = 1
                  AND (sp.has_date_range = 0 OR (DATE(sp.date_start) <= DATE(NOW()) AND DATE(sp.date_end) >= DATE(NOW())))
                  AND (
                    (sp.promo_type = \'percent\' AND sp.discount_percent > 0 AND sp.discount_percent <= 100)
                    OR (
                        sp.promo_type = \'fixed\'
                        AND sp.discount_fixed > 0
                        AND EXISTS (
                            SELECT 1
                            FROM __variants pvf
                            WHERE pvf.product_id = p.id
                              AND pvf.price >= sp.discount_fixed
                            LIMIT 1
                        )
                    )
                    OR sp.promo_type = \'bundle_3x2\'
                  )
                  AND (
                    CASE
                        WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = sp.id AND spo.exclude = 0 AND spo.type = \'product\')
                        THEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = sp.id AND spo.exclude = 0 AND spo.type = \'product\' AND spo.object_id = p.id)
                        ELSE 1
                    END = 1
                  )
                  AND (
                    CASE
                        WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = sp.id AND spo.exclude = 0 AND spo.type = \'category\')
                        THEN EXISTS (
                            SELECT 1 FROM __sviat__promo_object spo
                            WHERE spo.promo_id = sp.id AND spo.exclude = 0 AND spo.type = \'category\'
                            AND (
                                spo.object_id = p.main_category_id
                                OR EXISTS (SELECT 1 FROM __products_categories pc_sv WHERE pc_sv.product_id = p.id AND pc_sv.category_id = spo.object_id)
                            )
                        )
                        ELSE 1
                    END = 1
                  )
                  AND (
                    CASE
                        WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = sp.id AND spo.exclude = 0 AND spo.type = \'brand\')
                        THEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = sp.id AND spo.exclude = 0 AND spo.type = \'brand\' AND spo.object_id = p.brand_id)
                        ELSE 1
                    END = 1
                  )
                  AND (
                    CASE
                        WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = sp.id AND spo.exclude = 0 AND spo.type = \'feature_value\')
                        THEN (
                            SELECT COUNT(DISTINCT fv_chk.feature_id)
                            FROM __sviat__promo_object spo_fv
                            INNER JOIN __features_values fv_chk ON fv_chk.id = spo_fv.object_id
                            WHERE spo_fv.promo_id = sp.id AND spo_fv.exclude = 0 AND spo_fv.type = \'feature_value\'
                              AND spo_fv.object_id IN (SELECT pfv.value_id FROM __products_features_values pfv WHERE pfv.product_id = p.id)
                        ) = (
                            SELECT COUNT(DISTINCT fv_total.feature_id)
                            FROM __sviat__promo_object spo_total
                            INNER JOIN __features_values fv_total ON fv_total.id = spo_total.object_id
                            WHERE spo_total.promo_id = sp.id AND spo_total.exclude = 0 AND spo_total.type = \'feature_value\'
                        )
                        ELSE 1
                    END = 1
                  )
                  AND NOT (
                    EXISTS (
                        SELECT 1
                        FROM __sviat__promo_object spo_excl_any
                        WHERE spo_excl_any.promo_id = sp.id
                          AND spo_excl_any.exclude = 1
                    )
                    AND (
                        CASE
                            WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo_excl_p WHERE spo_excl_p.promo_id = sp.id AND spo_excl_p.exclude = 1 AND spo_excl_p.type = \'product\')
                            THEN EXISTS (SELECT 1 FROM __sviat__promo_object spo_excl_p WHERE spo_excl_p.promo_id = sp.id AND spo_excl_p.exclude = 1 AND spo_excl_p.type = \'product\' AND spo_excl_p.object_id = p.id)
                            ELSE 1
                        END = 1
                    )
                    AND (
                        CASE
                            WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo_excl_c WHERE spo_excl_c.promo_id = sp.id AND spo_excl_c.exclude = 1 AND spo_excl_c.type = \'category\')
                            THEN EXISTS (
                                SELECT 1
                                FROM __sviat__promo_object spo_excl_c
                                WHERE spo_excl_c.promo_id = sp.id AND spo_excl_c.exclude = 1 AND spo_excl_c.type = \'category\'
                                  AND (
                                      spo_excl_c.object_id = p.main_category_id
                                      OR EXISTS (
                                          SELECT 1
                                          FROM __products_categories pc_sv
                                          WHERE pc_sv.product_id = p.id
                                            AND pc_sv.category_id = spo_excl_c.object_id
                                      )
                                  )
                            )
                            ELSE 1
                        END = 1
                    )
                    AND (
                        CASE
                            WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo_excl_b WHERE spo_excl_b.promo_id = sp.id AND spo_excl_b.exclude = 1 AND spo_excl_b.type = \'brand\')
                            THEN EXISTS (SELECT 1 FROM __sviat__promo_object spo_excl_b WHERE spo_excl_b.promo_id = sp.id AND spo_excl_b.exclude = 1 AND spo_excl_b.type = \'brand\' AND spo_excl_b.object_id = p.brand_id)
                            ELSE 1
                        END = 1
                    )
                    AND (
                        CASE
                            WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo_excl_f WHERE spo_excl_f.promo_id = sp.id AND spo_excl_f.exclude = 1 AND spo_excl_f.type = \'feature_value\')
                            THEN (
                                SELECT COUNT(DISTINCT fv_chk.feature_id)
                                FROM __sviat__promo_object spo_fv
                                INNER JOIN __features_values fv_chk ON fv_chk.id = spo_fv.object_id
                                WHERE spo_fv.promo_id = sp.id AND spo_fv.exclude = 1 AND spo_fv.type = \'feature_value\'
                                  AND spo_fv.object_id IN (SELECT pfv.value_id FROM __products_features_values pfv WHERE pfv.product_id = p.id)
                            ) = (
                                SELECT COUNT(DISTINCT fv_total.feature_id)
                                FROM __sviat__promo_object spo_total
                                INNER JOIN __features_values fv_total ON fv_total.id = spo_total.object_id
                                WHERE spo_total.promo_id = sp.id AND spo_total.exclude = 1 AND spo_total.type = \'feature_value\'
                            )
                            ELSE 1
                        END = 1
                    )
                  )
                LIMIT 1
            )
        )';
    }

    /**
     * @param int|string $campaignId ідентифікатор запису в sviat__promos
     *
     * Логіка включень (І між типами, АБО у межах одного типу):
     * - Якщо обрано товари: товар МАЄ бути в списку товарів
     * - Якщо обрано категорії: товар МАЄ бути в БУДЬ-ЯКІЙ з них
     * - Якщо обрано бренди: товар МАЄ бути з БУДЬ-ЯКОГО з них
     * - Якщо обрано кілька типів: І між ними
     *
     * За допомогою CASE: якщо тип не обраний, умова пропускається (завжди істина).
     * Виключення завжди мають пріоритет.
     */
    public function forCampaignScope($campaignId): void
    {
        $cid = (int) $campaignId;

        // Для кожного типу: якщо тип є у включеннях, товар має йому відповідати.
        // Якщо тип не обраний у включеннях, умова пропускається (CASE... ELSE 1).

        $productCheck = 'CASE
            WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 0 AND spo.type = \'product\')
            THEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 0 AND spo.type = \'product\' AND spo.object_id = p.id)
            ELSE 1
        END';

        $categoryCheck = 'CASE
            WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 0 AND spo.type = \'category\')
            THEN EXISTS (
                SELECT 1 FROM __sviat__promo_object spo
                WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 0 AND spo.type = \'category\'
                AND (
                    spo.object_id = p.main_category_id
                    OR EXISTS (SELECT 1 FROM __products_categories pc_sv WHERE pc_sv.product_id = p.id AND pc_sv.category_id = spo.object_id)
                )
            )
            ELSE 1
        END';

        $brandCheck = 'CASE
            WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 0 AND spo.type = \'brand\')
            THEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 0 AND spo.type = \'brand\' AND spo.object_id = p.brand_id)
            ELSE 1
        END';

        $featureValueCheck = "CASE
    WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 0 AND spo.type = 'feature_value')
    THEN (
        SELECT COUNT(DISTINCT fv_chk.feature_id)
        FROM __sviat__promo_object spo_fv
        INNER JOIN __features_values fv_chk ON fv_chk.id = spo_fv.object_id
        WHERE spo_fv.promo_id = :sv_promo_cid AND spo_fv.exclude = 0 AND spo_fv.type = 'feature_value'
          AND spo_fv.object_id IN (SELECT pfv.value_id FROM __products_features_values pfv WHERE pfv.product_id = p.id)
    ) = (
        SELECT COUNT(DISTINCT fv_total.feature_id)
        FROM __sviat__promo_object spo_total
        INNER JOIN __features_values fv_total ON fv_total.id = spo_total.object_id
        WHERE spo_total.promo_id = :sv_promo_cid AND spo_total.exclude = 0 AND spo_total.type = 'feature_value'
    )
    ELSE 1
END";

        $hasExclusionRows = "EXISTS (
    SELECT 1 FROM __sviat__promo_object spo_excl_any
    WHERE spo_excl_any.promo_id = :sv_promo_cid
      AND spo_excl_any.exclude = 1
)";

        $excludeProductCheck = "CASE
    WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 1 AND spo.type = 'product')
    THEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 1 AND spo.type = 'product' AND spo.object_id = p.id)
    ELSE 1
END";

        $excludeCategoryCheck = "CASE
    WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 1 AND spo.type = 'category')
    THEN EXISTS (
        SELECT 1 FROM __sviat__promo_object spo
        WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 1 AND spo.type = 'category'
          AND (
              spo.object_id = p.main_category_id
              OR EXISTS (
                  SELECT 1 FROM __products_categories pc_sv
                  WHERE pc_sv.product_id = p.id AND pc_sv.category_id = spo.object_id
              )
          )
    )
    ELSE 1
END";

        $excludeBrandCheck = "CASE
    WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 1 AND spo.type = 'brand')
    THEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 1 AND spo.type = 'brand' AND spo.object_id = p.brand_id)
    ELSE 1
END";

        $excludeFeatureValueCheck = "CASE
    WHEN EXISTS (SELECT 1 FROM __sviat__promo_object spo WHERE spo.promo_id = :sv_promo_cid AND spo.exclude = 1 AND spo.type = 'feature_value')
    THEN (
        SELECT COUNT(DISTINCT fv_chk.feature_id)
        FROM __sviat__promo_object spo_fv
        INNER JOIN __features_values fv_chk ON fv_chk.id = spo_fv.object_id
        WHERE spo_fv.promo_id = :sv_promo_cid AND spo_fv.exclude = 1 AND spo_fv.type = 'feature_value'
          AND spo_fv.object_id IN (SELECT pfv.value_id FROM __products_features_values pfv WHERE pfv.product_id = p.id)
    ) = (
        SELECT COUNT(DISTINCT fv_total.feature_id)
        FROM __sviat__promo_object spo_total
        INNER JOIN __features_values fv_total ON fv_total.id = spo_total.object_id
        WHERE spo_total.promo_id = :sv_promo_cid AND spo_total.exclude = 1 AND spo_total.type = 'feature_value'
    )
    ELSE 1
END";

        // Виключення працюють так само як включення: AND між типами, OR всередині типу.
        $exclusionCheck = "NOT (
    $hasExclusionRows
    AND ($excludeProductCheck = 1)
    AND ($excludeCategoryCheck = 1)
    AND ($excludeBrandCheck = 1)
    AND ($excludeFeatureValueCheck = 1)
)";

        // Для fixed-акції товар потрапляє у вибірку лише якщо є варіант з ціною >= discount_fixed.
        // Для інших типів умову пропускаємо.
        $fixedPriceCheck = 'CASE
            WHEN EXISTS (
                SELECT 1
                FROM __sviat__promos sp
                WHERE sp.id = :sv_promo_cid
                  AND sp.promo_type = \'fixed\'
            )
            THEN EXISTS (
                SELECT 1
                FROM __sviat__promos sp
                WHERE sp.id = :sv_promo_cid
                  AND sp.discount_fixed > 0
                  AND EXISTS (
                      SELECT 1
                      FROM __variants pvf
                      WHERE pvf.product_id = p.id
                        AND pvf.price >= sp.discount_fixed
                      LIMIT 1
                  )
            )
            ELSE 1
        END';

        // Усі типи перевіряємо через "І", плюс виключення і fixed-перевірка.
        $this->select->where("($productCheck = 1) AND ($categoryCheck = 1) AND ($brandCheck = 1) AND ($featureValueCheck = 1) AND ($fixedPriceCheck = 1) AND $exclusionCheck");
        $this->select->bindValue('sv_promo_cid', $cid);
    }

    public function forDiscounted($state): void
    {
        if ((int) $state === 1) {
            $this->select->where($this->discountedWhereSql());
        } else {
            $this->select->where('NOT ' . $this->discountedWhereSql());
        }
    }

    public function forOtherFilter($filters): void
    {
        if (empty($filters) || !is_array($filters)) {
            return;
        }

        $otherFilter = [];
        if (in_array('featured', $filters, true)) {
            $otherFilter[] = 'p.featured = 1';
        }

        if (in_array('discounted', $filters, true)) {
            $otherFilter[] = $this->discountedWhereSql();
        }

        if ($otherFilter !== []) {
            $this->select->where('(' . implode(' OR ', $otherFilter) . ')');
        }
    }
}
