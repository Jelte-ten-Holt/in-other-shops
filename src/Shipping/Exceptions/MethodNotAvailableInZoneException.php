<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Exceptions;

final class MethodNotAvailableInZoneException extends ShippingException
{
    public static function for(string $methodIdentifier, string $zoneIdentifier): self
    {
        return new self(
            "Shipping method [{$methodIdentifier}] is not available in zone [{$zoneIdentifier}]."
        );
    }
}
