<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use InOtherShops\Shipping\DTOs\ShippingZone;
use InOtherShops\Shipping\ShippingConfig;

final class ResolveShippingZoneForCountry
{
    public function __invoke(string $countryCode): ?ShippingZone
    {
        return ShippingConfig::zoneByCountry($countryCode);
    }
}
