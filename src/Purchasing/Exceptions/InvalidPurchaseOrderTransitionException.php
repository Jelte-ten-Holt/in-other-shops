<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Exceptions;

use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;

final class InvalidPurchaseOrderTransitionException extends PurchasingException
{
    public static function between(PurchaseOrderStatus $from, PurchaseOrderStatus $to): self
    {
        return new self("Cannot transition purchase order from [{$from->value}] to [{$to->value}].");
    }
}
