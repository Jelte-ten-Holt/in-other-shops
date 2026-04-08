<?php

declare(strict_types=1);

namespace InOtherShops\Shipping;

use InOtherShops\Shipping\Models\Shipment;

final class Shipping
{
    public static function shipment(): Shipment
    {
        $class = config('shipping.models.shipment', Shipment::class);

        return new $class;
    }
}
