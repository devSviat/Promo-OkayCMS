<?php

namespace Okay\Modules\Sviat\Promo\Services;

use Okay\Core\EntityFactory;
use Okay\Entities\ProductsEntity;

class ProductsWithoutImageFilter
{
    /** @var EntityFactory */
    private $entityFactory;

    public function __construct(EntityFactory $entityFactory)
    {
        $this->entityFactory = $entityFactory;
    }

    /**
     * @param array<int|string> $productIds
     * @return int[]
     */
    public function filterIds(array $productIds): array
    {
        $normalized = [];
        foreach ($productIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $normalized[$intId] = $intId;
            }
        }
        if ($normalized === []) {
            return [];
        }

        /** @var ProductsEntity $products */
        $products = $this->entityFactory->get(ProductsEntity::class);
        $rows = $products->find(['id' => array_values($normalized)]);

        $kept = [];
        foreach ($rows as $row) {
            if (!empty($row->main_image_id) && (int) $row->main_image_id > 0) {
                $kept[] = (int) $row->id;
            }
        }
        return $kept;
    }
}
