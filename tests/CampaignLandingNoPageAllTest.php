<?php

namespace Modules\Sviat\Promo;

use Okay\Modules\Sviat\Promo\Controllers\CampaignLandingController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Лендинг акції підтримував page=all і вантажив усю кампанію одним запитом.
 * Скоп кампанії задається категорією чи брендом, тож накриває тисячі товарів:
 * заміряний лендинг з ~1000 товарів коштував 69 МБ, а кампанія на весь
 * каталог вийшла б за типові 256 МБ і впала б фаталом з кодом 200.
 *
 * Підтримку прибрано, а не обмежено стелею: посилання на page=all у шаблонах
 * немає, canonical лендинга і так веде на чистий URL, тож такий адрес
 * віддається звичайною першою сторінкою.
 *
 * Тест стереже саме те, що повернути це легко: у ядрі гілка з 'all' лишилась,
 * і скопіювати її сюди назад — питання одного рядка.
 */
class CampaignLandingNoPageAllTest extends TestCase
{
    /**
     * Читаємо код без коментарів: пояснення, чому page=all прибрано, саме
     * слово 'all' містить, і без цього тест ловив би власний докблок.
     */
    private function code(): string
    {
        $path = (new ReflectionClass(CampaignLandingController::class))->getFileName();
        $this->assertFileExists((string) $path);

        $code = '';
        foreach (token_get_all(file_get_contents((string) $path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    public function testLandingDoesNotHandlePageAll(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '~[\'"]all[\'"]~',
            $this->code(),
            'на лендинг повернувся page=all — кампанія знову вантажиться одним запитом'
        );
    }

    /**
     * Ліміт іде в запит напряму, тож нуль тут означає ділення на нуль вище
     * за рендер: незадане products_num клало б сторінку фаталом.
     */
    public function testItemsPerPageNeverFallsToZero(): void
    {
        $this->assertMatchesRegularExpression(
            '~\$itemsPerPage\s*=\s*max\(\s*1\s*,~',
            $this->code(),
            'products_num більше не має нижньої межі'
        );
    }
}
