<?php

declare(strict_types=1);

namespace InOtherShops\Shipping;

use InOtherShops\Shipping\Models\Shipment;

final class Shipping
{
    /** @return class-string<Shipment> */
    public static function shipment(): string
    {
        return config('shipping.models.shipment', Shipment::class);
    }
}
