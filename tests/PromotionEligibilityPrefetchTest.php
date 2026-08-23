<?php

namespace Modules\Sviat\Promo;

use Okay\Core\EntityFactory;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\FeaturesValuesEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoScopeEntity;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;
use PHPUnit\Framework\TestCase;

/**
 * До цього кожна картка товару коштувала ~5 SQL: категорії, значення
 * характеристик, два запити по скопах і вибірка кампаній. На каталозі з
 * 24 товарів це ~120 запитів із виміряних 233.
 */
class PromotionEligibilityPrefetchTest extends TestCase
{
    /** @var array<string,int> */
    private array $calls = [];

    private function makeEligibility(array $scopeRows, array $campaigns): PromotionEligibility
    {
        $this->calls = ['categories' => 0, 'featureValues' => 0, 'scope' => 0, 'campaigns' => 0];

        $categories = $this->createStub(CategoriesEntity::class);
        $categories->method('getProductCategories')
            ->willReturnCallback(function ($ids) {
                $this->calls['categories']++;
                $this->assertNotEmpty($ids, 'порожній фільтр віддав би всю таблицю');
                $out = [];
                foreach ((array) $ids as $pid) {
                    $out[] = (object) ['product_id' => $pid, 'category_id' => 18];
                }
                return $out;
            });

        $featureValues = $this->createStub(FeaturesValuesEntity::class);
        $featureValues->method('getProductValuesIds')
            ->willReturnCallback(function ($ids) {
                $this->calls['featureValues']++;
                $this->assertNotEmpty($ids, 'порожній фільтр віддав би всю таблицю');
                return [];
            });

        $scope = $this->createMock(PromoScopeEntity::class);
        $scope->method('noLimit')->willReturnSelf();
        // count() на цій сутності не існує як робочий шлях: у таблиці немає
        // колонки id, а Entity::count() будує COUNT(DISTINCT alias.id).
        $scope->expects($this->never())->method('count');
        $scope->method('find')->willReturnCallback(function () use ($scopeRows) {
            $this->calls['scope']++;
            return $scopeRows;
        });

        $campaignEntity = $this->createStub(PromoCampaignEntity::class);
        $campaignEntity->method('noLimit')->willReturnSelf();
        $campaignEntity->method('find')->willReturnCallback(function () use ($campaigns) {
            $this->calls['campaigns']++;
            return $campaigns;
        });

        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(
            function ($class) use ($categories, $featureValues, $scope, $campaignEntity) {
                switch ($class) {
                    case CategoriesEntity::class:    return $categories;
                    case FeaturesValuesEntity::class: return $featureValues;
                    case PromoScopeEntity::class:    return $scope;
                    case PromoCampaignEntity::class: return $campaignEntity;
                }
                return $this->createStub($class);
            }
        );

        return new PromotionEligibility($entityFactory);
    }

    private function products(int $count): array
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[$i] = (object) [
                'id' => $i,
                'brand_id' => 7,
                'main_category_id' => 18,
                'main_image_id' => 100 + $i,
            ];
        }
        return $out;
    }

    public function testPrefetchCostIsConstantRegardlessOfProductCount(): void
    {
        $scopeRows = [(object) ['promo_id' => 5, 'type' => 'category', 'object_id' => 18, 'exclude' => 0]];
        $campaigns = [(object) ['id' => 5, 'visible' => 1, 'exclude_no_image' => 0, 'promo_type' => 'percent']];

        $eligibility = $this->makeEligibility($scopeRows, $campaigns);
        $products = $this->products(24);

        $eligibility->prefetchForProducts($products);
        foreach ($products as $product) {
            $eligibility->promoIdsForProduct($product);
        }

        $this->assertSame(1, $this->calls['categories']);
        $this->assertSame(1, $this->calls['featureValues']);
        $this->assertSame(1, $this->calls['scope']);
        $this->assertLessThanOrEqual(1, $this->calls['campaigns']);
    }

    public function testPrefetchedResultMatchesPerProductPath(): void
    {
        $scopeRows = [(object) ['promo_id' => 5, 'type' => 'category', 'object_id' => 18, 'exclude' => 0]];
        $campaigns = [(object) ['id' => 5, 'visible' => 1, 'exclude_no_image' => 0, 'promo_type' => 'percent']];

        $eligibility = $this->makeEligibility($scopeRows, $campaigns);
        $products = $this->products(2);
        $eligibility->prefetchForProducts($products);

        foreach ($products as $product) {
            $this->assertSame([5], $eligibility->promoIdsForProduct($product));
        }
    }

    public function testProductOutsideScopeGetsNoPromos(): void
    {
        $scopeRows = [(object) ['promo_id' => 5, 'type' => 'category', 'object_id' => 999, 'exclude' => 0]];
        $campaigns = [(object) ['id' => 5, 'visible' => 1, 'exclude_no_image' => 0, 'promo_type' => 'percent']];

        $eligibility = $this->makeEligibility($scopeRows, $campaigns);
        $products = $this->products(1);
        $eligibility->prefetchForProducts($products);

        $this->assertSame([], $eligibility->promoIdsForProduct($products[1]));
    }

    public function testExclusionRowRemovesProductFromCampaign(): void
    {
        $scopeRows = [
            (object) ['promo_id' => 5, 'type' => 'category', 'object_id' => 18, 'exclude' => 0],
            (object) ['promo_id' => 5, 'type' => 'product',  'object_id' => 1,  'exclude' => 1],
        ];
        $campaigns = [(object) ['id' => 5, 'visible' => 1, 'exclude_no_image' => 0, 'promo_type' => 'percent']];

        $eligibility = $this->makeEligibility($scopeRows, $campaigns);
        $products = $this->products(2);
        $eligibility->prefetchForProducts($products);

        $this->assertSame([], $eligibility->promoIdsForProduct($products[1]), 'товар 1 виключено');
        $this->assertSame([5], $eligibility->promoIdsForProduct($products[2]));
    }

    /**
     * Непридатний рядок-виняток — це зіпсовані дані, а не «виняток порожній,
     * отже його немає». Пропустити його означало б віддати знижку товару,
     * який менеджер намагався виключити. Кампанія, що перестала працювати,
     * помітна одразу; зайва знижка спливає аж на звірці.
     */
    public function testCampaignWithOnlyUnusableExclusionRowsMatchesNothing(): void
    {
        $scopeRows = [
            (object) ['promo_id' => 5, 'type' => 'category', 'object_id' => 18, 'exclude' => 0],
            (object) ['promo_id' => 5, 'type' => 'product',  'object_id' => 0,  'exclude' => 1],
        ];
        $campaigns = [(object) ['id' => 5, 'visible' => 1, 'exclude_no_image' => 0, 'promo_type' => 'percent']];

        $eligibility = $this->makeEligibility($scopeRows, $campaigns);
        $products = $this->products(2);
        $eligibility->prefetchForProducts($products);

        foreach ($products as $product) {
            $this->assertSame([], $eligibility->promoIdsForProduct($product));
        }
    }

    /** Те саме для нерозпізнаного type у рядку-винятку. */
    public function testCampaignWithUnknownExclusionTypeMatchesNothing(): void
    {
        $scopeRows = [
            (object) ['promo_id' => 5, 'type' => 'category', 'object_id' => 18, 'exclude' => 0],
            (object) ['promo_id' => 5, 'type' => 'whatever', 'object_id' => 42, 'exclude' => 1],
        ];
        $campaigns = [(object) ['id' => 5, 'visible' => 1, 'exclude_no_image' => 0, 'promo_type' => 'percent']];

        $eligibility = $this->makeEligibility($scopeRows, $campaigns);
        $products = $this->products(2);
        $eligibility->prefetchForProducts($products);

        foreach ($products as $product) {
            $this->assertSame([], $eligibility->promoIdsForProduct($product));
        }
    }

    /**
     * Раніше такий рядок відсікався SQL-пре-фільтром: findPromoIdsForProduct()
     * вимагав збігу object_id, тож кампанія не потрапляла в кандидати. Після
     * переходу на матчинг у пам'яті непридатні рядки просто пропускаються —
     * і кампанія з порожнім скопом застосувалася б до ВСЬОГО каталогу.
     */
    public function testCampaignWithOnlyUnusableScopeRowsMatchesNothing(): void
    {
        $scopeRows = [(object) ['promo_id' => 5, 'type' => 'category', 'object_id' => 0, 'exclude' => 0]];
        $campaigns = [(object) ['id' => 5, 'visible' => 1, 'exclude_no_image' => 0, 'promo_type' => 'percent']];

        $eligibility = $this->makeEligibility($scopeRows, $campaigns);
        $products = $this->products(2);
        $eligibility->prefetchForProducts($products);

        foreach ($products as $product) {
            $this->assertSame([], $eligibility->promoIdsForProduct($product));
        }
    }

    public function testUnknownScopeTypeDoesNotMatchEverything(): void
    {
        $scopeRows = [(object) ['promo_id' => 5, 'type' => 'whatever', 'object_id' => 42, 'exclude' => 0]];
        $campaigns = [(object) ['id' => 5, 'visible' => 1, 'exclude_no_image' => 0, 'promo_type' => 'percent']];

        $eligibility = $this->makeEligibility($scopeRows, $campaigns);
        $products = $this->products(1);
        $eligibility->prefetchForProducts($products);

        $this->assertSame([], $eligibility->promoIdsForProduct($products[1]));
    }

    /**
     * Осиротілий рядок скопу (кампанію видалили повз CampaignRepository) не
     * має скидати кеш кампаній і повертати N+1 на кожну картку.
     */
    public function testOrphanScopeRowDoesNotReintroducePerCardQueries(): void
    {
        $scopeRows = [
            (object) ['promo_id' => 5,  'type' => 'category', 'object_id' => 18, 'exclude' => 0],
            (object) ['promo_id' => 77, 'type' => 'category', 'object_id' => 18, 'exclude' => 0],
        ];
        // Кампанії 77 не існує — find() її не поверне.
        $campaigns = [(object) ['id' => 5, 'visible' => 1, 'exclude_no_image' => 0, 'promo_type' => 'percent']];

        $eligibility = $this->makeEligibility($scopeRows, $campaigns);
        $products = $this->products(10);
        $eligibility->prefetchForProducts($products);

        foreach ($products as $product) {
            $eligibility->pickBestActiveCampaign($eligibility->promoIdsForProduct($product));
        }

        $this->assertLessThanOrEqual(
            1,
            $this->calls['campaigns'],
            'кеш кампаній не має вимикатись через відсутню кампанію'
        );
    }

    /**
     * Запобіжник: якщо скопів надто багато, читати таблицю цілком не можна —
     * маємо тихо відкотитись на потоварний шлях, а не з'їсти пам'ять.
     */
    public function testPrefetchBailsOutOnHugeScopeTable(): void
    {
        // Стелю видно з самої вибірки: просимо на рядок більше за ліміт і,
        // якщо він прийшов, читати таблицю цілком не можна.
        $overLimit = array_fill(0, 5001, (object) ['promo_id' => 1, 'type' => 'product', 'object_id' => 1, 'exclude' => 0]);

        $scope = $this->createMock(PromoScopeEntity::class);
        $scope->method('noLimit')->willReturnSelf();
        $scope->expects($this->never())->method('count');
        $scope->expects($this->once())
            ->method('find')
            ->with(['limit' => 5001])
            ->willReturn($overLimit);

        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(function ($class) use ($scope) {
            if ($class === PromoScopeEntity::class) {
                return $scope;
            }
            $stub = $this->createStub($class);
            if (method_exists($stub, 'getProductCategories')) {
                $stub->method('getProductCategories')->willReturn([]);
            }
            if (method_exists($stub, 'getProductValuesIds')) {
                $stub->method('getProductValuesIds')->willReturn([]);
            }
            return $stub;
        });

        // Двічі: рішення «таблиця завелика» має запамʼятатись, інакше кожен
        // блок товарів на головній платив би за власну перевірку.
        $eligibility = new PromotionEligibility($entityFactory);
        $eligibility->prefetchForProducts($this->products(3));
        $eligibility->prefetchForProducts($this->products(3));
    }
}
