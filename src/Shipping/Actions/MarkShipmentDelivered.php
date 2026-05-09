<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use Illuminate\Support\Carbon;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Events\ShipmentDelivered;
use InOtherShops\Shipping\Models\Shipment;

final class MarkShipmentDelivered
{
    public function __construct(
        private readonly UpdateShipmentStatus $updateStatus,
    ) {}

    public function __invoke(Shipment $shipment): Shipment
    {
        ($this->updateStatus)($shipment, ShipmentStatus::Delivered, [
            'delivered_at' => Carbon::now(),
        ]);

        ShipmentDelivered::dispatch($shipment);

        return $shipment;
    }
}
