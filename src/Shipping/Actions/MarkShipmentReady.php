<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Events\ShipmentReady;
use InOtherShops\Shipping\Models\Shipment;

final class MarkShipmentReady
{
    public function __construct(
        private readonly UpdateShipmentStatus $updateStatus,
    ) {}

    public function __invoke(Shipment $shipment): Shipment
    {
        ($this->updateStatus)($shipment, ShipmentStatus::Ready);

        ShipmentReady::dispatch($shipment);

        return $shipment;
    }
}
