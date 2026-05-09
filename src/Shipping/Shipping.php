<?php

declare(strict_types=1);

namespace InOtherShops\Shipping;

use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Shipping\Models\ShipmentItem;

final class Shipping
{
    /** @return class-string<Shipment> */
    public static function shipment(): string
    {
        return config('shipping.models.shipment', Shipment::class);
    }

    /** @return class-string<ShipmentItem> */
    public static function shipmentItem(): string
    {
        return config('shipping.models.shipment_item', ShipmentItem::class);
    }
}
