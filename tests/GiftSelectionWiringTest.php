<?php

namespace Modules\Sviat\Promo;

use PHPUnit\Framework\TestCase;

/**
 * Вибір подарунка на картці товару довго не доходив до сервера, і помітити це
 * було нічим: подарунок у замовленні все одно зʼявлявся — його підставляв
 * автопідбір у PromoCartHooks, завжди перший зі списку. Тобто з двох подарунків
 * покупець отримував не той, який обрав, і жодної помилки при цьому не було.
 *
 * Причина — у розмітці: модифікація модуля вставляє віджет СУСІДНІМ блоком
 * перед боксом форми, а обробник шукав його всередині того самого
 * .details_boxed__item.
 */
class GiftSelectionWiringTest extends TestCase
{
    public function testHandlerDoesNotAssumeTheWidgetSharesTheFormBox()
    {
        $js = $this->script();

        $this->assertStringNotContainsString(
            'closest(".details_boxed__item").find(".fn_gift',
            $js,
            'обробник знову привʼязаний до одного боксу з формою'
        );
    }

    public function testHandlerWalksUpFromTheFormToFindTheSelectedGift()
    {
        $js = $this->script();

        $this->assertStringContainsString('$form.parents()', $js);
        $this->assertStringContainsString('.fn_gift.selected', $js);
    }

    /**
     * Обхід угору без межі — це той самий дефект з іншого боку: на списку
     * товарів він піднімається до спільної сітки й хапає подарунок сусідньої
     * картки, тобто купівля товару поза акцією видає безкоштовний подарунок.
     *
     * Межа визначається складом, а не класом: більш ніж одна форма купівлі в
     * предку означає, що ми вже в списку.
     */
    public function testWalkStopsAtTheProductBoundary()
    {
        $js = $this->script();

        $this->assertStringContainsString('.find(".fn_variants").length > 1', $js);

        $stopAt = strpos($js, '.find(".fn_variants").length > 1');
        $giftAt = strpos($js, '$ancestor.find(".fn_gift.selected")');

        $this->assertNotFalse($stopAt, 'межу обходу прибрано');
        $this->assertNotFalse($giftAt, 'пошук подарунка не знайдено — тест застарів');
        $this->assertLessThan(
            $giftAt,
            $stopAt,
            'межа перевіряється після пошуку подарунка, тобто не спрацьовує'
        );
    }

    public function testChoiceIsPostedWithTheStorefrontToken()
    {
        $js = $this->script();

        $this->assertStringContainsString('customer_csrf_token', $js);
        $this->assertStringContainsString('okay_csrf=([0-9a-f]{64})', $js);
    }

    private function script()
    {
        $path = dirname(__DIR__, 4) . '/Okay/Modules/Sviat/Promo/design/js/campaign.js';

        $this->assertFileExists($path);

        return file_get_contents($path);
    }
}
