<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use Illuminate\Database\Eloquent\Model;
use InOtherShops\Shipping\DTOs\ShippingMethod;
use InOtherShops\Shipping\DTOs\ShippingZone;
use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Shipping\Shipping;

final class CreateShipmentForOrder
{
    public function __invoke(
        Model $order,
        ShippingMethod $method,
        ShippingZone $zone,
        int $cost,
    ): Shipment {
        $class = Shipping::shipment();

        /** @var Shipment $shipment */
        $shipment = new $class([
            'method' => $method->identifier,
            'cost' => $cost,
            'currency' => $zone->currency->value,
        ]);

        $shipment->shippable()->associate($order);
        $shipment->save();

        return $shipment;
    }
}
