<?php

declare(strict_types=1);

namespace InOtherShops\Shipping;

use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Shipping\Models\ShippingMethod;

final class Shipping
{
    /** @return class-string<Shipment> */
    public static function shipment(): string
    {
        return config('shipping.models.shipment', Shipment::class);
    }

    /** @return class-string<ShippingMethod> */
    public static function shippingMethod(): string
    {
        return config('shipping.models.shipping_method', ShippingMethod::class);
    }
}
