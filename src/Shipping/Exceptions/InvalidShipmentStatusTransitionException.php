<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Exceptions;

use InOtherShops\Shipping\Enums\ShipmentStatus;

final class InvalidShipmentStatusTransitionException extends ShippingException
{
    public static function between(ShipmentStatus $from, ShipmentStatus $to): self
    {
        return new self("Cannot transition shipment from {$from->value} to {$to->value}.");
    }
}
