<?php

namespace Okay\Modules\Sviat\Promo\Plugins;

use Okay\Core\Design;
use Okay\Modules\Sviat\Promo\Helpers\ScopedDesignVars;
use Okay\Core\SmartyPlugins\Func;

/**
 * Стікер баджа акції на прев’ю / сторінці товару (поле sviat_promo_badge_image на $product).
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
        if (empty($vars['product']) || empty($vars['product']->sviat_promo_badge_image)) {
            return '';
        }

        return $this->fetchWithVars($this->design, 'promo_badge_sticker.tpl', ['product' => $vars['product']]);
    }
}
