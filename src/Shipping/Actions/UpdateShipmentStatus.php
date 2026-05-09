<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Exceptions\InvalidShipmentStatusTransitionException;
use InOtherShops\Shipping\Models\Shipment;

/**
 * Internal transition helper. Prefer the typed actions.
 */
final class UpdateShipmentStatus
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(Shipment $shipment, ShipmentStatus $newStatus, array $attributes = []): Shipment
    {
        if (! $shipment->status->canTransitionTo($newStatus)) {
            throw InvalidShipmentStatusTransitionException::between($shipment->status, $newStatus);
        }

        $shipment->fill(['status' => $newStatus, ...$attributes])->save();

        return $shipment;
    }
}
