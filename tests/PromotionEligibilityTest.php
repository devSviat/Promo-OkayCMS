<?php

namespace Modules\Sviat\Promo;

use Okay\Core\Cart;
use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;

require_once __DIR__ . '/PromoTestCase.php';

class PromotionEligibilityTest extends PromoTestCase
{
    private function makeEligibility(?EntityFactory $factory = null): PromotionEligibility
    {
        return new PromotionEligibility($factory ?? $this->createStub(EntityFactory::class));
    }

    public function testCampaignDatesOkWhenNoDateRange(): void
    {
        $e = $this->makeEligibility();
        self::assertTrue($e->campaignDatesOk($this->buildPromo(['has_date_range' => 0])));
    }

    public function testCampaignDatesOkWhenWithinRange(): void
    {
        $e = $this->makeEligibility();
        $promo = $this->buildPromo([
            'has_date_range' => 1,
            'date_start' => date('Y-m-d H:i:s', time() - 3600),
            'date_end'   => date('Y-m-d H:i:s', time() + 3600),
        ]);
        self::assertTrue($e->campaignDatesOk($promo));
    }

    public function testCampaignDatesOkFailsForFuture(): void
    {
        $e = $this->makeEligibility();
        $promo = $this->buildPromo([
            'has_date_range' => 1,
            'date_start' => date('Y-m-d H:i:s', time() + 3600),
            'date_end'   => date('Y-m-d H:i:s', time() + 7200),
        ]);
        self::assertFalse($e->campaignDatesOk($promo));
    }

    public function testCampaignDatesOkFailsForPast(): void
    {
        $e = $this->makeEligibility();
        $promo = $this->buildPromo([
            'has_date_range' => 1,
            'date_start' => date('Y-m-d H:i:s', time() - 7200),
            'date_end'   => date('Y-m-d H:i:s', time() - 3600),
        ]);
        self::assertFalse($e->campaignDatesOk($promo));
    }

    public function testGetCartSubtotalSkipsBonusGifts(): void
    {
        $e = $this->makeEligibility();

        $cart = $this->createStub(Cart::class);
        $purchase1 = $this->buildPurchase(['price' => 100, 'amount' => 2]);
        $purchase2 = $this->buildPurchase(['variant' => (object) ['gift_product_id' => 5, 'price' => 50]]);
        $cart->purchases = [$purchase1, $purchase2];

        self::assertSame(200.0, $e->getCartSubtotal($cart));
    }

    public function testMinOrderSatisfiedTrueWhenNoThreshold(): void
    {
        $e = $this->makeEligibility();
        $cart = $this->createStub(Cart::class);
        $cart->purchases = [];
        self::assertTrue($e->minOrderSatisfied($this->buildPromo(['min_order_amount' => 0]), $cart));
    }

    public function testMinOrderSatisfiedFalseWhenBelowThreshold(): void
    {
        $e = $this->makeEligibility();
        $cart = $this->createStub(Cart::class);
        $cart->purchases = [$this->buildPurchase(['price' => 50, 'amount' => 1])];
        self::assertFalse($e->minOrderSatisfied($this->buildPromo(['min_order_amount' => 200]), $cart));
    }

    public function testPurchaseMatchesCampaignSkipsProductWithoutImageWhenFlagOn(): void
    {
        $productsEntity = $this->createStub(\Okay\Entities\ProductsEntity::class);
        $productsEntity->method('get')->willReturnCallback(function (int $id): ?object {
            if ($id === 100) {
                return (object) ['id' => 100, 'main_image_id' => 0, 'brand_id' => 0];
            }
            if ($id === 200) {
                return (object) ['id' => 200, 'main_image_id' => 11, 'brand_id' => 0];
            }
            return null;
        });

        $campaignsEntity = $this->createStub(\Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity::class);
        $campaignsEntity->method('findOne')->willReturn($this->buildPromo([
            'id' => 5,
            'exclude_no_image' => 1,
        ]));

        $scopeEntity = $this->createStub(\Okay\Modules\Sviat\Promo\Entities\PromoScopeEntity::class);
        $scopeEntity->method('noLimit')->willReturnSelf();
        $scopeEntity->method('find')->willReturn([
            (object) ['promo_id' => 5, 'type' => 'product', 'object_id' => 100, 'exclude' => 0],
            (object) ['promo_id' => 5, 'type' => 'product', 'object_id' => 200, 'exclude' => 0],
        ]);

        $categoriesEntity = $this->createStub(\Okay\Entities\CategoriesEntity::class);
        $categoriesEntity->method('getProductCategories')->willReturn([]);

        $featuresValuesEntity = $this->createStub(\Okay\Entities\FeaturesValuesEntity::class);
        $featuresValuesEntity->method('getProductValuesIds')->willReturn([]);

        $factory = $this->mockEntityFactory([
            \Okay\Entities\ProductsEntity::class                                 => $productsEntity,
            \Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity::class        => $campaignsEntity,
            \Okay\Modules\Sviat\Promo\Entities\PromoScopeEntity::class           => $scopeEntity,
            \Okay\Entities\CategoriesEntity::class                               => $categoriesEntity,
            \Okay\Entities\FeaturesValuesEntity::class                           => $featuresValuesEntity,
        ]);

        $e = new PromotionEligibility($factory);

        $imagelessPurchase = $this->buildPurchase(['product_id' => 100, 'product' => (object) ['id' => 100, 'main_image_id' => 0, 'brand_id' => 0]]);
        $withImagePurchase = $this->buildPurchase(['product_id' => 200, 'product' => (object) ['id' => 200, 'main_image_id' => 11, 'brand_id' => 0]]);

        self::assertFalse($e->purchaseMatchesCampaign($imagelessPurchase, 5), 'image-less product must be skipped when exclude_no_image=1');
        self::assertTrue($e->purchaseMatchesCampaign($withImagePurchase, 5), 'product with image must match');
    }
}