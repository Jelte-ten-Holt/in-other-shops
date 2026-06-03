<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing;

use InOtherShops\Purchasing\Models\PurchaseOrder;
use InOtherShops\Purchasing\Models\PurchaseOrderLine;
use InOtherShops\Purchasing\Models\Supplier;

final class Purchasing
{
    /** @return class-string<Supplier> */
    public static function supplier(): string
    {
        return config('purchasing.models.supplier', Supplier::class);
    }

    /** @return class-string<PurchaseOrder> */
    public static function purchaseOrder(): string
    {
        return config('purchasing.models.purchase_order', PurchaseOrder::class);
    }

    /** @return class-string<PurchaseOrderLine> */
    public static function purchaseOrderLine(): string
    {
        return config('purchasing.models.purchase_order_line', PurchaseOrderLine::class);
    }
}
