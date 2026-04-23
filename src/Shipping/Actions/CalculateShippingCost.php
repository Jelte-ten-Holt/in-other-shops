<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use InOtherShops\Location\Models\Address;
use InOtherShops\Shipping\Models\ShippingMethod;

final class CalculateShippingCost
{
    public function __invoke(ShippingMethod $method, ?Address $address = null): int
    {
        return (int) $method->base_cost;
    }
}
