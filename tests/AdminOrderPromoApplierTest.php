<?php

namespace Modules\Sviat\Promo;

use Okay\Entities\CurrenciesEntity;
use Okay\Entities\DiscountsEntity;
use Okay\Entities\ProductsEntity;
use Okay\Entities\PurchasesEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Services\AdminOrderPromoApplier;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;

require_once __DIR__ . '/PromoTestCase.php';

class AdminOrderPromoApplierTest extends PromoTestCase
{
    private function makeApplier(int $cents = 0, ?PromotionEligibility $eligibility = null, ?ProductsEntity $products = null, ?DiscountsEntity $discounts = null, ?PurchasesEntity $purchases = null): AdminOrderPromoApplier
    {
        $eligibility = $eligibility ?? $this->createMock(PromotionEligibility::class);
        $products    = $products    ?? $this->createMock(ProductsEntity::class);
        $discounts   = $discounts   ?? $this->createMock(DiscountsEntity::class);
        $purchases   = $purchases   ?? $this->createMock(PurchasesEntity::class);

        $factory = $this->mockEntityFactory([
            CurrenciesEntity::class => $this->mockCurrenciesEntity($cents),
            ProductsEntity::class   => $products,
            DiscountsEntity::class  => $discounts,
            PurchasesEntity::class  => $purchases,
        ]);

        return new AdminOrderPromoApplier($factory, $eligibility);
    }

    public function testRememberAddedPurchaseStoresInfo(): void
    {
        $applier = $this->makeApplier();
        $purchase = (object) ['product_id' => 5, 'variant_id' => 50, 'amount' => 2, 'undiscounted_price' => 270.0];

        $applier->rememberAddedPurchase(101, $purchase);

        $ref = self::accessible(new \ReflectionProperty(AdminOrderPromoApplier::class, 'newPurchases'));
        $state = $ref->getValue($applier);

        self::assertSame([101 => [
            'purchase_id'        => 101,
            'product_id'         => 5,
            'variant_id'         => 50,
            'amount'             => 2,
            'undiscounted_price' => 270.0,
        ]], $state);
    }

    public function testRememberAddedPurchaseIgnoresFalsyId(): void
    {
        $applier = $this->makeApplier();
        $applier->rememberAddedPurchase(0, (object) ['product_id' => 5]);
        $applier->rememberAddedPurchase(null, (object) ['product_id' => 5]);

        $ref = self::accessible(new \ReflectionProperty(AdminOrderPromoApplier::class, 'newPurchases'));
        self::assertSame([], $ref->getValue($applier));
    }

    public function testApplyPromosDoesNothingWithoutRememberedPurchases(): void
    {
        $discounts = $this->createMock(DiscountsEntity::class);
        $discounts->expects(self::never())->method('add');
        $applier = $this->makeApplier(0, null, null, $discounts);

        self::assertNull($applier->applyPromosToNewPurchases(null, (object) ['id' => 1]));
    }

    public function testApplyPercentPromoCreatesDiscountAndUpdatesPrice(): void
    {
        $products = $this->createMock(ProductsEntity::class);
        $product  = (object) ['id' => 5, 'brand_id' => 0, 'main_image_id' => 11, 'main_category_id' => 0];
        $products->method('get')->willReturnCallback(fn ($id) => $id === 5 ? $product : null);

        $eligibility = $this->createMock(PromotionEligibility::class);
        $eligibility->method('promoIdsForProduct')->willReturnCallback(fn ($p) => $p === $product ? [7] : []);
        $eligibility->method('getActiveCampaigns')->willReturn([
            $this->buildPromo([
                'id' => 7,
                'name' => 'ASUS',
                'promo_type' => PromoCampaignEntity::TYPE_PERCENT,
                'discount_percent' => 15,
            ]),
        ]);

        $discounts = $this->createMock(DiscountsEntity::class);
        $discounts->expects(self::once())
            ->method('add')
            ->with(self::callback(function (array $row): bool {
                return $row['entity'] === 'purchase'
                    && $row['entity_id'] === 101
                    && $row['type'] === 'percent'
                    && abs((float) $row['value'] - 15.0) < 0.001
                    && $row['from_last_discount'] === 1
                    && $row['name'] === 'ASUS';
            }));

        $purchases = $this->createMock(PurchasesEntity::class);
        // cents=0: 270 * 0.85 = 229.5 → round → 230
        $purchases->expects(self::once())
            ->method('update')
            ->with(101, ['price' => 230.0]);

        $applier = $this->makeApplier(0, $eligibility, $products, $discounts, $purchases);

        $applier->rememberAddedPurchase(101, (object) ['product_id' => 5, 'variant_id' => 50, 'amount' => 1, 'undiscounted_price' => 270.0]);
        $applier->applyPromosToNewPurchases(null, (object) ['id' => 17106]);

        // State must be cleared after run.
        $ref = self::accessible(new \ReflectionProperty(AdminOrderPromoApplier::class, 'newPurchases'));
        self::assertSame([], $ref->getValue($applier));
    }

    public function testApplyFixedPromoCreatesAbsoluteDiscount(): void
    {
        $products = $this->createMock(ProductsEntity::class);
        $product  = (object) ['id' => 5, 'brand_id' => 0, 'main_image_id' => 11, 'main_category_id' => 0];
        $products->method('get')->willReturn($product);

        $eligibility = $this->createMock(PromotionEligibility::class);
        $eligibility->method('promoIdsForProduct')->willReturn([8]);
        $eligibility->method('getActiveCampaigns')->willReturn([
            $this->buildPromo([
                'id' => 8,
                'name' => 'Знижка 50',
                'promo_type' => PromoCampaignEntity::TYPE_FIXED,
                'discount_fixed' => 50,
            ]),
        ]);

        $discounts = $this->createMock(DiscountsEntity::class);
        $discounts->expects(self::once())
            ->method('add')
            ->with(self::callback(function (array $row): bool {
                return $row['type'] === 'absolute' && abs((float) $row['value'] - 50.0) < 0.001;
            }));

        $purchases = $this->createMock(PurchasesEntity::class);
        $purchases->expects(self::once())
            ->method('update')
            ->with(101, ['price' => 220.0]); // cents=0, 270 - 50 = 220

        $applier = $this->makeApplier(0, $eligibility, $products, $discounts, $purchases);

        $applier->rememberAddedPurchase(101, (object) ['product_id' => 5, 'variant_id' => 50, 'amount' => 1, 'undiscounted_price' => 270.0]);
        $applier->applyPromosToNewPurchases(null, (object) ['id' => 17106]);
    }

    public function testApplyPromoSkipsWhenNoActiveCampaign(): void
    {
        $products = $this->createMock(ProductsEntity::class);
        $products->method('get')->willReturn((object) ['id' => 5, 'main_image_id' => 11]);

        $eligibility = $this->createMock(PromotionEligibility::class);
        $eligibility->method('promoIdsForProduct')->willReturn([]);

        $discounts = $this->createMock(DiscountsEntity::class);
        $discounts->expects(self::never())->method('add');

        $purchases = $this->createMock(PurchasesEntity::class);
        $purchases->expects(self::never())->method('update');

        $applier = $this->makeApplier(0, $eligibility, $products, $discounts, $purchases);

        $applier->rememberAddedPurchase(101, (object) ['product_id' => 5, 'variant_id' => 50, 'amount' => 1, 'undiscounted_price' => 270.0]);
        $applier->applyPromosToNewPurchases(null, (object) ['id' => 17106]);
    }

    public function testStateIsClearedEvenIfDiscountEntityThrows(): void
    {
        $products = $this->createMock(ProductsEntity::class);
        $products->method('get')->willReturn((object) ['id' => 5, 'main_image_id' => 11]);

        $eligibility = $this->createMock(PromotionEligibility::class);
        $eligibility->method('promoIdsForProduct')->willReturn([7]);
        $eligibility->method('getActiveCampaigns')->willReturn([
            $this->buildPromo(['id' => 7, 'promo_type' => PromoCampaignEntity::TYPE_PERCENT, 'discount_percent' => 10]),
        ]);

        $discounts = $this->createMock(DiscountsEntity::class);
        $discounts->method('add')->willThrowException(new \RuntimeException('db fail'));

        $applier = $this->makeApplier(0, $eligibility, $products, $discounts);
        $applier->rememberAddedPurchase(101, (object) ['product_id' => 5, 'variant_id' => 50, 'amount' => 1, 'undiscounted_price' => 100.0]);

        try {
            $applier->applyPromosToNewPurchases(null, (object) ['id' => 17106]);
            self::fail('Expected exception');
        } catch (\RuntimeException $e) {
            $ref = self::accessible(new \ReflectionProperty(AdminOrderPromoApplier::class, 'newPurchases'));
            self::assertSame([], $ref->getValue($applier), 'state must be cleared even on error');
        }
    }
}
