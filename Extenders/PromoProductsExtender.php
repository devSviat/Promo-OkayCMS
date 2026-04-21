<?php

namespace Okay\Modules\Sviat\Promo\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Promo\Services\PromoProductDisplayService;

/**
 * Підстановка ціни з акції для списків і картки товару (getList + attachProductData).
 */
class PromoProductsExtender implements ExtensionInterface
{
    /** @var PromoProductDisplayService */
    private $productDisplay;

    public function __construct(PromoProductDisplayService $productDisplay)
    {
        $this->productDisplay = $productDisplay;
    }

    /**
     * @param array|mixed $products
     * @return array|mixed
     */
    public function decorateListProducts($products)
    {
        if (!is_array($products) || $products === []) {
            return $products;
        }
        foreach ($products as $product) {
            $this->productDisplay->decorateProduct($product);
        }

        return $products;
    }

    /**
     * Сторінка одного товару: {@see ProductsHelper::attachProductData} не проходить через getList.
     *
     * @param object|false|mixed $product
     * @return object|false|mixed
     */
    public function decorateProductAfterAttach($product)
    {
        if (!is_object($product) || empty($product->id) || empty($product->variant)) {
            return $product;
        }
        $this->productDisplay->decorateProduct($product);

        return $product;
    }
}
