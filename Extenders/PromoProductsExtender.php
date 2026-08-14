<?php

namespace Okay\Modules\Sviat\Promo\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\Promo\Services\PromoProductDisplayService;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;

/**
 * Підстановка ціни з акції для списків і картки товару (getList + attachProductData).
 */
class PromoProductsExtender implements ExtensionInterface
{
    /** @var PromoProductDisplayService */
    private $productDisplay;

    /** @var PromotionEligibility */
    private $eligibility;

    public function __construct(
        PromoProductDisplayService $productDisplay,
        PromotionEligibility $eligibility
    ) {
        $this->productDisplay = $productDisplay;
        $this->eligibility = $eligibility;
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
        // Один префетч на весь список замість ~5 SQL на кожен товар.
        $this->eligibility->prefetchForProducts($products);
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
