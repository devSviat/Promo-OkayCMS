<?php

namespace Okay\Modules\Sviat\Promo\Plugins;

use Okay\Core\SmartyPlugins\Modifier;

/**
 * Значення числового поля форми акції без хвоста DECIMAL-колонки:
 * 0 і null → порожньо (поле показує placeholder), 500.0000 → 500, 499.5000 → 499.5.
 */
class MoneyInputModifier extends Modifier
{
    protected $tag = 'sviat_promo_money_input';

    public function run($value = null): string
    {
        if (!is_numeric($value)) {
            return '';
        }
        $number = round((float) $value, 2);
        if ($number == 0) {
            return '';
        }

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
