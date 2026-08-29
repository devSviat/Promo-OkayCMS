<?php

namespace Okay\Modules\Sviat\Promo\Services;

/** Знецінення товарного кешу, якщо він у системі взагалі є. */
interface ProductCacheInvalidation
{
    public function isAvailable(): bool;

    public function invalidateProductData(): void;
}
