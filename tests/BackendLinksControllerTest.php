<?php

namespace Modules\Sviat\Promo;

use Okay\Modules\Sviat\Promo\Backend\Controllers\CampaignListAdmin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * {url controller=CampaignListAdmin} без вендора й модуля адмінка шукає серед контролерів
 * ядра, не знаходить і віддає «Товари»: так поводилась кнопка «Назад» на картці акції,
 * відкритій без ?return=.
 */
class BackendLinksControllerTest extends TestCase
{
    public function testEveryLinkNamesAnExistingController(): void
    {
        $designDir = dirname((string) (new ReflectionClass(CampaignListAdmin::class))->getFileName(), 2) . '/design/html';
        $templates = glob($designDir . '/*.tpl') ?: [];
        self::assertNotSame([], $templates);

        $unresolved = [];
        foreach ($templates as $template) {
            preg_match_all('/\{url\b[^}]*\bcontroller=([^\s}]+)/', (string) file_get_contents($template), $matches);
            foreach ($matches[1] as $controller) {
                if (!$this->resolves(trim($controller, '\'"'))) {
                    $unresolved[] = basename($template) . ': ' . $controller;
                }
            }
        }

        self::assertSame([], $unresolved);
    }

    private function resolves(string $controller): bool
    {
        if (preg_match('/^\[(\w+),\s*(\w+),\s*(\w+)\]$/', $controller, $parts)
            || preg_match('/^(\w+)\.(\w+)\.(\w+)$/', $controller, $parts)) {
            return class_exists("Okay\\Modules\\{$parts[1]}\\{$parts[2]}\\Backend\\Controllers\\{$parts[3]}");
        }

        return class_exists("Okay\\Admin\\Controllers\\{$controller}");
    }
}
