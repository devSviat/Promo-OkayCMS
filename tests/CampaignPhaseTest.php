<?php

namespace Modules\Sviat\Promo;

use Okay\Modules\Sviat\Promo\Controllers\CampaignLandingController;
use Okay\Modules\Sviat\Promo\Services\CampaignPhase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Лендинг завершеної акції лишається відкритим для людей (200, «Завершено»),
 * але без товарів це тонка сторінка, тож пошуковикам віддається noindex,follow.
 * Активна й майбутня акції індексуються як і раніше.
 */
class CampaignPhaseTest extends TestCase
{
    private const int NOW = 1_780_000_000;

    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    private static function promo(int $hasDateRange, ?int $start, ?int $end): object
    {
        return (object) [
            'has_date_range' => $hasDateRange,
            'date_start'     => $start === null ? null : date('Y-m-d H:i:s', $start),
            'date_end'       => $end === null ? null : date('Y-m-d H:i:s', $end),
        ];
    }

    public static function phases(): array
    {
        $day = 86400;

        return [
            'без дат'                       => [0, null, null, CampaignPhase::Active],
            'без дат, але зі старим кінцем' => [0, self::NOW - 10 * $day, self::NOW - $day, CampaignPhase::Active],
            'триває'                        => [1, self::NOW - $day, self::NOW + $day, CampaignPhase::Active],
            'кінець рівно зараз'            => [1, self::NOW - $day, self::NOW, CampaignPhase::Active],
            'завершилась'                   => [1, self::NOW - 10 * $day, self::NOW - 1, CampaignPhase::Ended],
            'ще не почалась'                => [1, self::NOW + $day, self::NOW + 10 * $day, CampaignPhase::Upcoming],
            'кінець раніше за старт'        => [1, self::NOW + $day, self::NOW - $day, CampaignPhase::Ended],
            'дати порожні'                  => [1, null, null, CampaignPhase::Active],
        ];
    }

    #[DataProvider('phases')]
    public function testPhaseFollowsCampaignDates(int $hasDateRange, ?int $start, ?int $end, CampaignPhase $expected): void
    {
        $this->assertSame($expected, CampaignPhase::fromPromo(self::promo($hasDateRange, $start, $end), self::NOW));
    }

    public function testOnlyEndedCampaignIsHiddenFromIndex(): void
    {
        $this->assertTrue(CampaignPhase::Active->isIndexable());
        $this->assertTrue(CampaignPhase::Upcoming->isIndexable());
        $this->assertFalse(CampaignPhase::Ended->isIndexable());
    }

    /**
     * Дати акції в БД записані за годинником магазину, а не UTC: о 00:30 за Києвом
     * акція, що тривала до 23:59:59, уже завершена, хоча в UTC ще 21:30.
     */
    public function testDatesAreReadInShopTimezone(): void
    {
        date_default_timezone_set('Europe/Kyiv');
        $promo = (object) ['has_date_range' => 1, 'date_start' => '2026-05-01 00:00:00', 'date_end' => '2026-05-31 23:59:59'];

        $this->assertSame(CampaignPhase::Ended, CampaignPhase::fromPromo($promo, strtotime('2026-05-31 21:30:00 UTC')));
        $this->assertSame(CampaignPhase::Active, CampaignPhase::fromPromo($promo, strtotime('2026-05-31 20:30:00 UTC')));
    }

    /**
     * Повний render() тут не прогнати: він тягне статичний Router. Тож тримаємо
     * зв'язку на рівні коду, а саму сторінку перевірено curl-ом на стенді.
     */
    public function testLandingMarksEndedCampaignNoindexFollow(): void
    {
        $code = '';
        $path = (string) (new ReflectionClass(CampaignLandingController::class))->getFileName();
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertMatchesRegularExpression('~\$phase\s*=\s*CampaignPhase::fromPromo\(\s*\$promo\s*,~', $code);
        $this->assertMatchesRegularExpression(
            "~if\s*\(\s*!\s*\\\$phase->isIndexable\(\)\s*\)\s*\{\s*\\\$this->design->assign\(\s*'noindex_follow'\s*,\s*true\s*\)~",
            $code,
            'завершена акція має віддавати noindex,follow'
        );
    }
}
