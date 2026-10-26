<?php

namespace Okay\Modules\Sviat\Promo\Helpers;

use Okay\Core\Design;

trait ScopedDesignVars
{
    /**
     * Renders a template with temporary Smarty variables and restores the previous values.
     *
     * Design::assign() is global: a plugin called from a product card would otherwise leave
     * the card's $product (or $promo) in place of the page's one, and head.tpl renders later
     * from those variables (og:image, meta).
     *
     * @param array<string, mixed> $vars
     */
    private function fetchWithVars(Design $design, string $template, array $vars): string
    {
        $previous = [];
        foreach ($vars as $name => $value) {
            $previous[$name] = $design->getVar($name);
            $design->assign($name, $value);
        }

        try {
            return $design->fetch($template);
        } finally {
            foreach ($previous as $name => $value) {
                $design->assign($name, $value);
            }
        }
    }
}
