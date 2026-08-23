<?php

namespace Modules\Sviat\Promo;

use Okay\Entities\ProductsEntity;
use Okay\Modules\Sviat\Promo\Services\ProductsWithoutImageFilter;

require_once __DIR__ . '/PromoTestCase.php';

class ProductsWithoutImageFilterTest extends PromoTestCase
{
    public function testReturnsEmptyArrayForEmptyInput(): void
    {
        $products = $this->createMock(ProductsEntity::class);
        $products->expects(self::never())->method('find');
        $factory = $this->mockEntityFactory([ProductsEntity::class => $products]);
        $filter = new ProductsWithoutImageFilter($factory);

        self::assertSame([], $filter->filterIds([]));
    }

    public function testKeepsOnlyProductsWithImage(): void
    {
        $products = $this->createStub(ProductsEntity::class);
        $products->method('find')->willReturn([
            (object) ['id' => 1, 'main_image_id' => 10],
            (object) ['id' => 2, 'main_image_id' => 0],
            (object) ['id' => 3, 'main_image_id' => null],
            (object) ['id' => 4, 'main_image_id' => 11],
        ]);
        $factory = $this->mockEntityFactory([ProductsEntity::class => $products]);
        $filter = new ProductsWithoutImageFilter($factory);

        self::assertSame([1, 4], array_values($filter->filterIds([1, 2, 3, 4])));
    }

    public function testIgnoresNonPositiveIds(): void
    {
        $products = $this->createMock(ProductsEntity::class);
        $products->expects(self::never())->method('find');
        $factory = $this->mockEntityFactory([ProductsEntity::class => $products]);
        $filter = new ProductsWithoutImageFilter($factory);

        self::assertSame([], $filter->filterIds([0, -3, '']));
    }
}
