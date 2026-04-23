<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use Illuminate\Database\Eloquent\Model;
use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Shipping\Models\ShippingMethod;
use InOtherShops\Shipping\Shipping;

final class CreateShipmentForOrder
{
    public function __invoke(Model $order, ShippingMethod $method): Shipment
    {
        $class = Shipping::shipment();

        /** @var Shipment $shipment */
        $shipment = new $class([
            'method' => $method->identifier,
            'cost' => (int) $method->base_cost,
            'currency' => $method->currency instanceof \BackedEnum ? $method->currency->value : (string) $method->currency,
        ]);

        $shipment->shippable()->associate($order);
        $shipment->save();

        return $shipment;
    }
}
