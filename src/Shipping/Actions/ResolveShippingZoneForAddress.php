<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use InOtherShops\Location\Models\Address;
use InOtherShops\Shipping\DTOs\ShippingZone;

final class ResolveShippingZoneForAddress
{
    public function __construct(
        private readonly ResolveShippingZoneForCountry $resolveForCountry,
    ) {}

    public function __invoke(Address $address): ?ShippingZone
    {
        return ($this->resolveForCountry)((string) $address->country_code);
    }
}
