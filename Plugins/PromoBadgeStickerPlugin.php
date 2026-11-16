<?php

namespace Okay\Modules\Sviat\Promo\Plugins;

use Okay\Core\Design;
use Okay\Modules\Sviat\Promo\Helpers\ScopedDesignVars;
use Okay\Core\SmartyPlugins\Func;

/**
 * Стікер баджа акції на прев’ю / сторінці товару (поле sviat_promo_badge_image на $product).
 * З conditional=1 (картки) ще й стікер «−N %» для варіантів, яким знижку дасть
 * лише замовлення від мінімальної суми ($product->sviat_promo_conditions).
 */
class PromoBadgeStickerPlugin extends Func
{
    use ScopedDesignVars;

    protected $tag = 'sviat_promo_badge_sticker';

    /** @var Design */
    private $design;

    public function __construct(Design $design)
    {
        $this->design = $design;
    }

    public function run($vars)
    {
        if (empty($vars['product'])) {
            return '';
        }
        $product = $vars['product'];
        $showConditions = !empty($vars['conditional']) && !empty($product->sviat_promo_conditions);
        if (empty($product->sviat_promo_badge_image) && !$showConditions) {
            return '';
        }

        return $this->fetchWithVars($this->design, 'promo_badge_sticker.tpl', [
            'product' => $product,
            'sviat_promo_show_conditions' => $showConditions,
        ]);
    }
}
