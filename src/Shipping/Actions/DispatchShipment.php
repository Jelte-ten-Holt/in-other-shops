<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use Illuminate\Support\Carbon;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Events\ShipmentDispatched;
use InOtherShops\Shipping\Models\Shipment;

final class DispatchShipment
{
    public function __construct(
        private readonly UpdateShipmentStatus $updateStatus,
    ) {}

    /**
     * Mark a shipment as handed to the carrier. When `$trackingUrl` is null,
     * the carrier's `tracking_url_template` from `config('shipping.carriers')`
     * is applied if available; carriers without a template (or with one-off
     * URLs that can't be templated) require an explicit `$trackingUrl`.
     */
    public function __invoke(
        Shipment $shipment,
        string $trackingNumber,
        string $carrier,
        ?string $trackingUrl = null,
    ): Shipment {
        ($this->updateStatus)($shipment, ShipmentStatus::InTransit, [
            'carrier' => $carrier,
            'tracking_number' => $trackingNumber,
            'tracking_url' => $trackingUrl ?? $this->resolveTrackingUrl($carrier, $trackingNumber),
            'shipped_at' => Carbon::now(),
        ]);

        ShipmentDispatched::dispatch($shipment);

        return $shipment;
    }

    private function resolveTrackingUrl(string $carrier, string $trackingNumber): ?string
    {
        $template = config("shipping.carriers.{$carrier}.tracking_url_template");

        if (! is_string($template) || $template === '') {
            return null;
        }

        return str_replace('{tracking_number}', $trackingNumber, $template);
    }
}
