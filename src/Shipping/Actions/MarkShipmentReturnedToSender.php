<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Events\ShipmentReturnedToSender;
use InOtherShops\Shipping\Models\Shipment;

final class MarkShipmentReturnedToSender
{
    public function __construct(
        private readonly UpdateShipmentStatus $updateStatus,
    ) {}

    public function __invoke(Shipment $shipment, string $reason): Shipment
    {
        ($this->updateStatus)($shipment, ShipmentStatus::ReturnedToSender);

        ShipmentReturnedToSender::dispatch($shipment, $reason);

        return $shipment;
    }
}
