<?php

namespace Okay\Modules\Sviat\Promo\Services;

use Okay\Core\Classes\Discount;
use Okay\Core\Modules\Extender\ExtensionInterface;

/**
 * Зберігає в БД знижку, яка дає ту саму ціну при перерахунку.
 *
 * Форма замовлення постить усі знижки, і ядро заново рахує ціну з type і value.
 * Якщо кінцеву ціну округлено, вихідний відсоток дає іншу суму, тож збереження
 * замовлення без змін міняло б ціну й суму. Шлях адмін-замовлень робить те саме
 * в AdminOrderPromoApplier.
 */
class PromoDiscountForDb implements ExtensionInterface
{
    private const float PRECISION = 0.005;

    /**
     * @param array{0: object, 1: mixed} $result обʼєкт знижки для БД і переклади
     */
    public function keepActualAmount(array $result, Discount $discount, $entity, $entityId): array
    {
        if ($discount->sign !== 'sviat_promo') {
            return $result;
        }

        $configured = $discount->type === 'percent'
            ? (float) $discount->priceBeforeDiscount * (float) $discount->value / 100
            : (float) $discount->value;
        $actual = (float) $discount->absoluteDiscount;

        if (abs($configured - $actual) > self::PRECISION) {
            $result[0]->type = 'absolute';
            $result[0]->value = $actual;
        }

        return $result;
    }
}
