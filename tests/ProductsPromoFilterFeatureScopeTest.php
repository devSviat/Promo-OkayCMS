<?php

namespace Modules\Sviat\Promo;

use Okay\Core\QueryFactory\Select;
use Okay\Modules\Sviat\Promo\ExtendsEntities\ProductsPromoFilter;
use PHPUnit\Framework\TestCase;

/**
 * Скоп по характеристиках рахується у двох місцях: PHP-евристикою в
 * PromotionEligibility (ціна на картці й у кошику) і цим SQL (лістинги та
 * лендінг акції). Значення істини має бути одне.
 *
 * Рядок скопу переживає видалення свого значення характеристики — зовнішніх
 * ключів немає, а прибирання є лише при видаленні самої кампанії. Приєднання
 * __features_values робило такий рядок невидимим одразу для чисельника й
 * знаменника, тож порівняння COUNT вироджувалось у 0 = 0 і давало «умова
 * виконана»: включення накривало весь каталог, виняток вимикав кампанію.
 * Рахувати треба по збереженій spo.feature_id — тій самій колонці, якій
 * довіряє PHP.
 */
class ProductsPromoFilterFeatureScopeTest extends TestCase
{
    /** @return list<string> */
    private function capturedSql(string $method, $argument): array
    {
        $captured = [];
        $select = $this->createStub(Select::class);
        $select->method('where')->willReturnCallback(function (...$args) use (&$captured) {
            $captured[] = (string) ($args[0] ?? '');
        });
        $select->method('bindValue')->willReturnCallback(function () {});

        $filter = new ProductsPromoFilter();
        $filter->setSelect($select);
        $filter->{$method}($argument);

        return $captured;
    }

    /** @return list<string> */
    public static function scopeSqlBuilders(): array
    {
        return [
            'лістинг зі знижкою' => ['forDiscounted', 1],
            'лендінг акції'      => ['forCampaignScope', 1],
        ];
    }

    /**
     * @dataProvider scopeSqlBuilders
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scopeSqlBuilders')]
    public function testFeatureScopeIsCountedWithoutJoiningFeatureValues(string $method, $argument): void
    {
        $sql = implode("\n", $this->capturedSql($method, $argument));

        self::assertNotSame('', $sql, 'фільтр має додати умову');
        self::assertStringNotContainsString(
            'INNER JOIN __features_values',
            $sql,
            'приєднання ховає рядок скопу з видаленим значенням — обидва COUNT стають нулями'
        );
    }

    /**
     * @dataProvider scopeSqlBuilders
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scopeSqlBuilders')]
    public function testFeatureGroupsAreCountedByTheStoredFeatureId(string $method, $argument): void
    {
        $sql = implode("\n", $this->capturedSql($method, $argument));

        preg_match_all('/COUNT\(DISTINCT\s+([a-z_]+)\.feature_id\)/i', $sql, $matches);
        self::assertNotEmpty($matches[1], 'скоп по характеристиках має рахуватись');

        foreach ($matches[1] as $alias) {
            self::assertStringStartsWith(
                'spo',
                $alias,
                'рахувати треба по колонці рядка скопу, а не по приєднаному довіднику'
            );
        }
    }
}
