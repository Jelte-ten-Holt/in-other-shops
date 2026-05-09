<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Shipping\DTOs\ShippingMethod;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Events\ShipmentCreated;
use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Shipping\Shipping;

final class CreateShipment
{
    /**
     * Create a Shipment in {@see ShipmentStatus::Pending} for the given order.
     *
     * @param  Collection<int, OrderLine>|null  $orderLines  defaults to all of the order's lines
     */
    public function __invoke(
        Model $order,
        ShippingMethod $method,
        ?Collection $orderLines = null,
    ): Shipment {
        $shipment = DB::transaction(function () use ($order, $method, $orderLines): Shipment {
            $class = Shipping::shipment();

            /** @var Shipment $shipment */
            $shipment = new $class([
                'method' => $method->identifier,
                'status' => ShipmentStatus::Pending,
            ]);

            $shipment->shippable()->associate($order);
            $shipment->save();

            $this->attachItems($shipment, $order, $orderLines);

            return $shipment;
        });

        ShipmentCreated::dispatch($shipment);

        return $shipment;
    }

    /**
     * @param  Collection<int, OrderLine>|null  $orderLines
     */
    private function attachItems(Shipment $shipment, Model $order, ?Collection $orderLines): void
    {
        $lines = $orderLines ?? $order->lines;

        $itemClass = Shipping::shipmentItem();

        foreach ($lines as $line) {
            $item = new $itemClass([
                'order_line_id' => $line->getKey(),
                'quantity' => $line->quantity,
            ]);
            $item->shipment()->associate($shipment);
            $item->save();
        }
    }
}
