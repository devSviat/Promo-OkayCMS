<?php

namespace Okay\Modules\Sviat\Promo\Plugins;

use Okay\Core\Design;
use Okay\Core\SmartyPlugins\Func;

/**
 * Стікер баджа акції на прев’ю / сторінці товару (поле sviat_promo_badge_image на $product).
 */
class PromoBadgeStickerPlugin extends Func
{
    protected $tag = 'sviat_promo_badge_sticker';

    /** @var Design */
    private $design;

    public function __construct(Design $design)
    {
        $this->design = $design;
    }

    public function run($vars)
    {
        if (empty($vars['product']) || empty($vars['product']->sviat_promo_badge_image)) {
            return '';
        }

        $this->design->assign('product', $vars['product']);

        return $this->design->fetch('promo_badge_sticker.tpl');
    }
}
