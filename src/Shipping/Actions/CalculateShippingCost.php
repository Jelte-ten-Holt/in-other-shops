<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use InOtherShops\Shipping\DTOs\ShippingMethod;
use InOtherShops\Shipping\DTOs\ShippingZone;
use InOtherShops\Shipping\Exceptions\MethodNotAvailableInZoneException;

final class CalculateShippingCost
{
    public function __invoke(
        ShippingMethod $method,
        ShippingZone $zone,
        ?int $subtotalCents = null,
    ): int {
        $rate = $method->rateForZone($zone->identifier);

        if ($rate === null) {
            throw MethodNotAvailableInZoneException::for($method->identifier, $zone->identifier);
        }

        if ($subtotalCents !== null && $zone->qualifiesForFreeShipping($subtotalCents)) {
            return 0;
        }

        return $rate;
    }
}
