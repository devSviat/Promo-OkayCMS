<?php

namespace Okay\Modules\Sviat\Promo\Services;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Entities\CurrenciesEntity;

/**
 * Rounds admin-edited purchase prices to currency precision.
 * Registered as ChainExtender on BackendOrdersHelper::prepareCommonPurchase.
 *
 * BackendOrdersHelper::prepareCommonPurchase recomputes purchase->price from
 * undiscounted_price minus discounts but never rounds — so a percent discount
 * on a currencies.cents=0 currency leaves e.g. 229.5 instead of 230.
 */
class AdminPurchasePriceRounder implements ExtensionInterface
{
    /** @var int */
    private $currencyPrecision;

    public function __construct(EntityFactory $entityFactory)
    {
        /** @var CurrenciesEntity $currenciesEntity */
        $currenciesEntity = $entityFactory->get(CurrenciesEntity::class);
        $mainCurrency = $currenciesEntity->getMainCurrency();
        $this->currencyPrecision = max(0, (int) ($mainCurrency->cents ?? 2));
    }

    /**
     * @param object $purchase prepared purchase row returned by prepareCommonPurchase()
     * @param mixed  $order    original arg (unused)
     * @param mixed  $rawPurchase original arg (unused)
     * @param mixed  $discounts   original arg (unused)
     */
    public function normalize($purchase, $order, $rawPurchase, $discounts)
    {
        if (!is_object($purchase)) {
            return $purchase;
        }
        if (isset($purchase->price) && is_numeric($purchase->price)) {
            $purchase->price = round((float) $purchase->price, $this->currencyPrecision);
        }
        if (isset($purchase->undiscounted_price) && is_numeric($purchase->undiscounted_price)) {
            $purchase->undiscounted_price = round((float) $purchase->undiscounted_price, $this->currencyPrecision);
        }
        return $purchase;
    }
}