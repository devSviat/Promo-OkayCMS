<?php

namespace Modules\Sviat\Promo;

use Okay\Controllers\AbstractController;
use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Modules\Sviat\Promo\Controllers\GiftSelectionEndpoint;
use Okay\Modules\Sviat\Promo\Services\PromotionEligibility;
use PHPUnit\Framework\TestCase;

/**
 * requireCustomerCsrf() є лише в AbstractController форку. Безумовний виклик
 * на стоковому OkayCMS валив вибір подарунка фатальною помилкою (HTTP 500).
 */
class GiftSelectionEndpointStockTest extends TestCase
{
    public function testEndpointRunsOnEngineWithoutCustomerCsrf()
    {
        // @phpstan-ignore function.alreadyNarrowedType (на форку метод є завжди; на стоці — ні)
        if (method_exists(AbstractController::class, 'requireCustomerCsrf')) {
            $this->markTestSkipped('рушій з requireCustomerCsrf(): перевірка діє, а не пропускається');
        }

        // Request::method() — власний метод класу, тож PHPUnit-заглушка на ньому не налаштовується.
        $request = new class extends Request {
            public function __construct()
            {
            }

            public function post($name = null, $type = null, $default = null)
            {
                return 0;
            }
        };

        $endpoint = (new \ReflectionClass(GiftSelectionEndpoint::class))->newInstanceWithoutConstructor();
        $result = $endpoint->ajaxAddGiftToCart(
            $request,
            $this->createStub(EntityFactory::class),
            $this->createStub(PromotionEligibility::class)
        );

        $this->assertNull($result);
    }
}
