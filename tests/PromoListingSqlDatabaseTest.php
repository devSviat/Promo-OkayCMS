<?php

namespace Modules\Sviat\Promo;

use Aura\Sql\ExtendedPdo;
use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Core\ServiceLocator;
use Okay\Modules\Sviat\Promo\ExtendsEntities\ProductsPromoFilter;
use Okay\Modules\Sviat\Promo\Services\PromoProductDisplayService;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * SQL лістингу «зі знижкою» і лендингу акції проти PHP-правила вітрини на живій MariaDB.
 * Для кожного товару фікстури SQL має сказати «є знижка» тоді й лише тоді, коли
 * PromoProductDisplayService справді знижує хоч один його варіант.
 *
 * Усе в транзакції, що відкочується. Без бази чи колонки min_product_price (2.1.0)
 * тест пропускається: модульний CI бази не має, тому його ганяють на стендах.
 */
class PromoListingSqlDatabaseTest extends TestCase
{
    private const BASE_ID = 990_100_000;

    private ExtendedPdo $pdo;
    private string $prefix;
    private bool $inTransaction = false;

    /** @var array<string, int> */
    private array $campaigns = [];

    /** @var array<string, int> */
    private array $products = [];

    /** @var array<string, list<string>> назва акції → назви товарів у її скопі */
    private const SCOPE = [
        'percent' => ['below', 'mix', 'edge', 'usd_above', 'zero', 'usd_below'],
        'fixed' => ['fixed_above', 'fixed_split', 'fixed_half_rate'],
        'plain' => ['plain_zero', 'plain_cheap'],
    ];

    /** @var array<string, list<array{float, string}>> товар → [ціна, валюта] */
    private const VARIANTS = [
        'below' => [[800.0, 'main']],
        'mix' => [[800.0, 'main'], [1200.0, 'main']],
        'edge' => [[1000.0, 'main']],
        'usd_above' => [[40.0, 'x30']],
        'usd_below' => [[30.0, 'x30']],
        'zero' => [[0.0, 'main']],
        'fixed_above' => [[1600.0, 'main']],
        // Один варіант проходить лише поріг, другий лише суму знижки без перерахунку валюти.
        'fixed_split' => [[1200.0, 'main'], [1600.0, 'half']],
        'fixed_half_rate' => [[3200.0, 'half']],
        // Без порогу: варіант за 0 знижки не має, як і на вітрині.
        'plain_zero' => [[0.0, 'main']],
        'plain_cheap' => [[50.0, 'main']],
    ];

    /** @var array<string, int> валюта → id */
    private array $currencies = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = ServiceLocator::getInstance()->getService(ExtendedPdo::class);
            $this->prefix = (string) ServiceLocator::getInstance()->getService(Config::class)->get('db_prefix');
            $this->pdo->query("SELECT min_product_price FROM {$this->prefix}sviat__promos LIMIT 1");
        } catch (\Throwable $e) {
            $this->markTestSkipped('немає бази або Promo 2.1.0: ' . $e->getMessage());
        }
        $this->assertMatchesRegularExpression('/^[a-z0-9_]*$/i', $this->prefix);
        $this->assertSame(0, (int) $this->pdo->fetchValue(
            "SELECT COUNT(*) FROM {$this->prefix}products WHERE id >= " . self::BASE_ID . ' AND id < ' . (self::BASE_ID + 100)
        ), 'id товарів фікстури зайняті');

        $this->pdo->beginTransaction();
        $this->inTransaction = true;
        $this->seed();
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            $this->pdo->rollBack();
        }
    }

    private function seed(): void
    {
        $p = $this->prefix;
        $this->currencies['main'] = (int) $this->pdo->fetchValue("SELECT id FROM {$p}currencies WHERE rate_from = rate_to ORDER BY position LIMIT 1");
        foreach (['x30' => [1, 30], 'half' => [2, 1]] as $code => [$from, $to]) {
            $this->pdo->perform(
                "INSERT INTO {$p}currencies (name, sign, code, rate_from, rate_to, cents, position, enabled) VALUES (:n, :n, :c, :f, :t, 2, 999, 0)",
                ['n' => 'promo-sql-test-' . $code, 'c' => strtoupper(substr('Z' . $code, 0, 3)), 'f' => $from, 't' => $to]
            );
            $this->currencies[$code] = (int) $this->pdo->lastInsertId();
        }

        $id = self::BASE_ID;
        foreach (self::VARIANTS as $name => $variants) {
            $this->products[$name] = ++$id;
            $this->pdo->perform(
                "INSERT INTO {$p}products (id, url, brand_id, main_category_id, main_image_id, visible) VALUES (:id, :url, 0, 0, 1, 1)",
                ['id' => $id, 'url' => 'promo-sql-test-' . $id]
            );
            foreach ($variants as $position => [$price, $currency]) {
                $this->pdo->perform(
                    "INSERT INTO {$p}variants (product_id, price, compare_price, currency_id, stock, position) VALUES (:pid, :price, 0, :cur, NULL, :pos)",
                    ['pid' => $id, 'price' => $price, 'cur' => $this->currencies[$currency], 'pos' => $position]
                );
            }
        }

        $definitions = [
            'percent' => ['promo_type' => 'percent', 'discount_percent' => 10, 'discount_fixed' => null, 'min_product_price' => 1000],
            'fixed' => ['promo_type' => 'fixed', 'discount_percent' => null, 'discount_fixed' => 1500, 'min_product_price' => 1000],
            'plain' => ['promo_type' => 'percent', 'discount_percent' => 5, 'discount_fixed' => null, 'min_product_price' => 0],
        ];
        foreach ($definitions as $name => $fields) {
            $this->pdo->perform(
                "INSERT INTO {$p}sviat__promos (url, annotation, description, promo_type, discount_percent, discount_fixed, min_order_amount, min_product_price, visible, has_date_range, exclude_no_image, position)
                 VALUES (:url, '', '', :type, :pct, :fixed, 0, :min, 1, 0, 0, 0)",
                ['url' => 'promo-sql-test-' . $name, 'type' => $fields['promo_type'], 'pct' => $fields['discount_percent'], 'fixed' => $fields['discount_fixed'], 'min' => $fields['min_product_price']]
            );
            $this->campaigns[$name] = (int) $this->pdo->lastInsertId();
            foreach (self::SCOPE[$name] as $product) {
                $this->pdo->perform(
                    "INSERT INTO {$p}sviat__promo_object (promo_id, object_id, type, exclude) VALUES (:promo, :obj, 'product', 0)",
                    ['promo' => $this->campaigns[$name], 'obj' => $this->products[$product]]
                );
            }
        }
    }

    /** @return list<int> id товарів фікстури, які пропускає SQL-фільтр */
    private function sqlProducts(string $method, int $argument): array
    {
        $select = ServiceLocator::getInstance()->getService(QueryFactory::class)->newSelect();
        $select->from('__products AS p')->cols(['p.id'])
            ->where('p.id IN (:sv_test_ids)')
            ->bindValue('sv_test_ids', array_values($this->products));
        $filter = new ProductsPromoFilter();
        $filter->setSelect($select);
        $filter->{$method}($argument);

        $ids = array_map(intval(...), array_column(array_map(fn($row) => (array) $row, $select->results()), 'id'));
        sort($ids);

        return $ids;
    }

    /** @return list<int> id товарів, у яких PromoProductDisplayService знижує хоч один варіант */
    private function phpProducts(?int $onlyCampaign = null): array
    {
        $entityFactory = ServiceLocator::getInstance()->getService(EntityFactory::class);
        $eligibility = new PromotionEligibility($entityFactory);
        $display = new PromoProductDisplayService($eligibility, new NullLogger(), $entityFactory);
        $rates = [];
        foreach ($this->pdo->fetchAll("SELECT id, rate_from, rate_to FROM {$this->prefix}currencies") as $row) {
            $rates[(int) $row['id']] = $row;
        }

        $ids = [];
        foreach ($this->products as $productId) {
            $variants = [];
            foreach ($this->pdo->fetchAll("SELECT id, price, currency_id FROM {$this->prefix}variants WHERE product_id = :id ORDER BY position", ['id' => $productId]) as $row) {
                $rate = $rates[(int) $row['currency_id']] ?? null;
                $price = (float) $row['price'];
                if ($rate !== null && $rate['rate_from'] != $rate['rate_to']) {
                    $price = round($price * $rate['rate_to'] / $rate['rate_from'], 2);
                }
                $variants[(int) $row['id']] = (object) ['id' => (int) $row['id'], 'price' => $price, 'compare_price' => null];
            }
            $product = (object) ['id' => $productId, 'brand_id' => 0, 'main_category_id' => 0, 'main_image_id' => 1, 'variants' => $variants, 'variant' => reset($variants)];
            if ($onlyCampaign !== null && !in_array($onlyCampaign, $eligibility->promoIdsForProduct($product), true)) {
                continue;
            }
            $display->decorateProduct($product);
            foreach ($product->variants as $variant) {
                if (isset($variant->sviat_promo_base_price)) {
                    $ids[] = $productId;
                    break;
                }
            }
        }
        sort($ids);

        return $ids;
    }

    /** @param list<string> $names */
    private function ids(array $names): array
    {
        $ids = array_map(fn(string $n): int => $this->products[$n], $names);
        sort($ids);

        return $ids;
    }

    public function testDiscountedListingMatchesStorefrontRule(): void
    {
        $expected = $this->ids(['mix', 'edge', 'usd_above', 'fixed_above', 'fixed_half_rate', 'plain_cheap']);

        self::assertSame($expected, $this->phpProducts(), 'PHP-правило вітрини на фікстурі');
        self::assertSame($expected, $this->sqlProducts('forDiscounted', 1));
    }

    public function testCampaignLandingMatchesStorefrontRule(): void
    {
        foreach ($this->campaigns as $name => $campaignId) {
            $php = $this->phpProducts($campaignId);
            self::assertNotSame([], $php, $name);
            self::assertSame($php, $this->sqlProducts('forCampaignScope', $campaignId), $name);
        }
    }

    /** Поріг і сума fixed-знижки мусять виконуватися на одному варіанті, у основній валюті. */
    public function testFixedCampaignNeedsOneVariantPassingBothConditions(): void
    {
        $fixed = $this->sqlProducts('forCampaignScope', $this->campaigns['fixed']);

        self::assertNotContains($this->products['fixed_split'], $fixed);
        self::assertContains($this->products['fixed_half_rate'], $fixed);
        self::assertContains($this->products['fixed_above'], $fixed);
    }
}
