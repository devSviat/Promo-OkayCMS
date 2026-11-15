<?php

namespace Modules\Sviat\Promo;

use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\Select;
use Okay\Core\Request;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoCampaignEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoFeedLinkEntity;
use Okay\Modules\Sviat\Promo\Entities\PromoScopeEntity;
use Okay\Modules\Sviat\Promo\Plugins\ProductCampaignBlockPlugin;
use Okay\Modules\Sviat\Promo\Requests\CampaignPayloadRequest;
use Okay\Modules\Sviat\Promo\Services\PromoFeedPriceResolver;
use Okay\Modules\Sviat\Promo\Services\PromoProductDisplayService;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/PromoTestCase.php';

/**
 * Акція з мінімальною сумою замовлення: ціна зі знижкою на вітрині лише з перемикачем
 * show_min_order_price, і тоді з підписом умови. Фід бере тільки гарантовані ціни.
 */
class PromoMinOrderDisplayTest extends PromoTestCase
{
    /** @param object[] $campaigns */
    private function decorate(array $campaigns): object
    {
        $eligibility = $this->createStub(PromotionEligibility::class);
        $eligibility->method('promoIdsForProduct')->willReturn(array_map(static fn(object $c): int => (int) $c->id, $campaigns));
        $eligibility->method('getActiveCampaigns')->willReturn($campaigns);
        $svc = new PromoProductDisplayService(
            $eligibility,
            $this->createStub(LoggerInterface::class),
            $this->mockEntityFactory([CurrenciesEntity::class => $this->mockCurrenciesEntity(2)])
        );
        $product = (object) ['id' => 1, 'variant' => (object) ['price' => 1000.0, 'compare_price' => null]];
        $svc->decorateProduct($product);

        return $product;
    }

    private function percent(array $overrides = []): \stdClass
    {
        return $this->buildPromo(array_merge(['id' => 7, 'promo_type' => PromoCampaignEntity::TYPE_PERCENT, 'discount_percent' => 10], $overrides));
    }

    public function testWithoutMinOrderShowsStrikethroughAndNoNote(): void
    {
        $p = $this->decorate([$this->percent()]);

        self::assertSame(1000.0, $p->variant->compare_price);
        self::assertSame(900.0, $p->variant->price);
        self::assertObjectNotHasProperty('sviat_promo_min_order_amount', $p);
    }

    public function testMinOrderWithSettingOffKeepsRegularPrice(): void
    {
        $p = $this->decorate([$this->percent(['min_order_amount' => 500])]);

        self::assertNull($p->variant->compare_price);
        self::assertSame(1000.0, $p->variant->price);
        self::assertObjectNotHasProperty('sviat_promo_min_order_amount', $p);
        self::assertObjectNotHasProperty('sviat_promo_price_display_applied', $p);
    }

    public function testMinOrderWithSettingOnShowsStrikethroughAndNote(): void
    {
        $p = $this->decorate([$this->percent(['min_order_amount' => 500, 'show_min_order_price' => 1])]);

        self::assertSame(1000.0, $p->variant->compare_price);
        self::assertSame(900.0, $p->variant->price);
        self::assertSame(500.0, $p->sviat_promo_min_order_amount);
    }

    public function testFixedMinOrderWithSettingOn(): void
    {
        $p = $this->decorate([$this->buildPromo([
            'id' => 8, 'promo_type' => PromoCampaignEntity::TYPE_FIXED, 'discount_fixed' => 150,
            'min_order_amount' => 2000, 'show_min_order_price' => '1',
        ])]);

        self::assertSame(1000.0, $p->variant->compare_price);
        self::assertSame(850.0, $p->variant->price);
        self::assertSame(2000.0, $p->sviat_promo_min_order_amount);
    }

    /** Вимкнена акція з мінімумом не заступає наступну акцію без мінімуму. */
    public function testSettingOffFallsBackToNextGuaranteedCampaign(): void
    {
        $p = $this->decorate([
            $this->percent(['min_order_amount' => 500, 'discount_percent' => 30]),
            $this->percent(['id' => 9, 'discount_percent' => 5]),
        ]);

        self::assertSame(1000.0, $p->variant->compare_price);
        self::assertSame(950.0, $p->variant->price);
        self::assertObjectNotHasProperty('sviat_promo_min_order_amount', $p);
    }

    /** Сторінка товару, коли ціну не підставив extender: блок акції рахує її сам. */
    public function testCampaignBlockComputesPriceOnlyWithSettingOn(): void
    {
        $plugin = new \ReflectionClass(ProductCampaignBlockPlugin::class)->newInstanceWithoutConstructor();
        $precision = self::accessible(new \ReflectionProperty(ProductCampaignBlockPlugin::class, 'currencyPrecision'));
        $precision->setValue($plugin, 2);
        $compute = self::accessible(new \ReflectionMethod(ProductCampaignBlockPlugin::class, 'computeEffectivePrice'));

        $off = $this->percent(['min_order_amount' => 500]);
        $compute->invoke($plugin, $off, (object) ['variant' => (object) ['price' => 1000.0]]);
        self::assertObjectNotHasProperty('sviat_promo_effective_price', $off);

        $on = $this->percent(['min_order_amount' => 500, 'show_min_order_price' => 1]);
        $compute->invoke($plugin, $on, (object) ['variant' => (object) ['price' => 1000.0]]);
        self::assertSame(900.0, $on->sviat_promo_effective_price);
    }

    /** @return array<int, object> кампанії, які фід вважає активними */
    private function feedCampaigns(object $campaign): array
    {
        $campaigns = $this->createStub(PromoCampaignEntity::class);
        $campaigns->method('noLimit')->willReturnSelf();
        $campaigns->method('find')->willReturn([$campaign]);
        $scopes = $this->createStub(PromoScopeEntity::class);
        $scopes->method('noLimit')->willReturnSelf();
        $scopes->method('find')->willReturn([]);
        $links = $this->createStub(PromoFeedLinkEntity::class);
        $links->method('getLinkedFeedsGrouped')->willReturn([(int) $campaign->id => ['feeds' => [99]]]);
        $eligibility = $this->createStub(PromotionEligibility::class);
        $eligibility->method('campaignDatesOk')->willReturn(true);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('cols')->willReturnSelf();
        $select->method('results')->willReturn([]);
        $queryFactory = $this->createStub(QueryFactory::class);
        $queryFactory->method('newSelect')->willReturn($select);

        $resolver = new PromoFeedPriceResolver(
            $this->mockEntityFactory([
                CurrenciesEntity::class => $this->mockCurrenciesEntity(2),
                PromoCampaignEntity::class => $campaigns,
                PromoScopeEntity::class => $scopes,
                PromoFeedLinkEntity::class => $links,
            ]),
            $queryFactory,
            $eligibility
        );
        $resolver->preload();

        return self::accessible(new \ReflectionProperty(PromoFeedPriceResolver::class, 'activeCampaigns'))->getValue($resolver);
    }

    public function testFeedSkipsMinOrderCampaignEvenWithSettingOn(): void
    {
        $guaranteed = $this->feedCampaigns($this->percent(['feed_enabled' => 1]));
        self::assertArrayHasKey(7, $guaranteed, 'контроль: акцію без мінімуму фід бере');

        $conditional = $this->feedCampaigns($this->percent(['feed_enabled' => 1, 'min_order_amount' => 500, 'show_min_order_price' => 1]));
        self::assertSame([], $conditional);
    }

    /** @param array<string, mixed> $post */
    private function postPromo(array $post): \stdClass
    {
        $saved = $_POST;
        $_POST = $post;
        try {
            return new CampaignPayloadRequest(new Request())->postPromo();
        } finally {
            $_POST = $saved;
        }
    }

    public function testSettingIsSavedOnlyForPriceCampaignWithMinOrder(): void
    {
        $on = ['promo_type' => 'percent', 'min_order_amount' => '500', 'show_min_order_price' => ['0', '1']];

        self::assertSame(1, $this->postPromo($on)->show_min_order_price);
        self::assertSame(0, $this->postPromo(['show_min_order_price' => ['0']] + $on)->show_min_order_price);
        self::assertSame(0, $this->postPromo(['min_order_amount' => '0'] + $on)->show_min_order_price);
        self::assertSame(0, $this->postPromo(['promo_type' => 'gift'] + $on)->show_min_order_price);
    }

    private static function renderNote(object $product, string $currencySign = 'грн'): string
    {
        $root = dirname(__DIR__, 4);
        // Сток 4.5.3 має Smarty 4 (клас \Smarty), форк — Smarty 5 (\Smarty\Smarty); потрібні методи однакові.
        $class = class_exists('Smarty\\Smarty') ? 'Smarty\\Smarty' : 'Smarty';
        $smarty = new $class();
        $smarty->setTemplateDir([$root . '/Okay/Modules/Sviat/Promo/design/html']);
        $smarty->setCompileDir(sys_get_temp_dir() . '/promo-min-order-note-' . getmypid());
        $smarty->muteUndefinedOrNullWarnings();
        $smarty->registerPlugin('modifier', 'convert', static fn($price): string => number_format((float) $price, 0, '.', ' '));
        $smarty->assign('lang', (object) ['sviat_promo__min_order_notice_from' => 'застосується при сумі замовлення від']);
        $smarty->assign('currency', (object) ['sign' => $currencySign]);
        $smarty->assign('product', $product);

        return trim($smarty->fetch('promo_min_order_note.tpl'));
    }

    public function testNoteTemplateRendersConditionOnlyWhenSet(): void
    {
        $html = self::renderNote((object) ['sviat_promo_min_order_amount' => 1500.0]);
        self::assertStringContainsString('class="sviat_promo_min_order_note"', $html);
        self::assertSame('застосується при сумі замовлення від 1 500 грн', trim(strip_tags($html)));

        self::assertSame('', self::renderNote((object) ['id' => 1]));
    }

    public function testNoteTemplateEscapesCurrencySign(): void
    {
        $html = self::renderNote((object) ['sviat_promo_min_order_amount' => 500.0], '<b>₴</b>');

        self::assertStringContainsString('&lt;b&gt;₴&lt;/b&gt;', $html);
    }

    /** Підпис додають модифікації обох шаблонів, у яких тема малює стару ціну. */
    public function testNoteIsWiredIntoProductAndListTemplates(): void
    {
        $module = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/Okay/Modules/Sviat/Promo/Init/module.json'), true);
        $wired = [];
        foreach ($module['modifications']['front'] as $mod) {
            foreach ($mod['changes'] as $change) {
                if (($change['appendAfter'] ?? '') === 'promo_min_order_note.tpl') {
                    $wired[$mod['file']] = $change['find'];
                }
            }
        }

        self::assertSame(['product.tpl' => 'details_boxed__prices', 'product_list.tpl' => 'product_preview__prices'], $wired);
    }
}
