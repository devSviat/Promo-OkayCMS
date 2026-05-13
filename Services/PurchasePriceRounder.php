<?php

namespace Okay\Modules\Sviat\Promo\Services;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Entities\CurrenciesEntity;

/**
 * Rounds order purchase prices to the main currency precision before persisting.
 * Registered as a ChainExtender on Purchase::getForDB.
 */
class PurchasePriceRounder implements ExtensionInterface
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
     * @param object $purchaseDb Object built by Purchase::getForDB().
     * @param mixed  $orderId    Original argument (unused).
     */
    public function normalize($purchaseDb, $orderId)
    {
        if (!is_object($purchaseDb)) {
            return $purchaseDb;
        }
        if (isset($purchaseDb->price) && is_numeric($purchaseDb->price)) {
            $purchaseDb->price = round((float) $purchaseDb->price, $this->currencyPrecision);
        }
        if (isset($purchaseDb->undiscounted_price) && is_numeric($purchaseDb->undiscounted_price)) {
            $purchaseDb->undiscounted_price = round((float) $purchaseDb->undiscounted_price, $this->currencyPrecision);
        }
        return $purchaseDb;
    }
}
