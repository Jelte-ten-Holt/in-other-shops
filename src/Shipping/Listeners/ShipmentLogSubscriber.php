<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogSubscriberBase;
use InOtherShops\Shipping\Events\ShipmentCreated;
use InOtherShops\Shipping\Events\ShipmentDelivered;
use InOtherShops\Shipping\Events\ShipmentDispatched;
use InOtherShops\Shipping\Events\ShipmentLost;
use InOtherShops\Shipping\Events\ShipmentReady;
use InOtherShops\Shipping\Events\ShipmentReturnedToSender;
use InOtherShops\Shipping\Models\Shipment;

final class ShipmentLogSubscriber extends LogSubscriberBase
{
    protected const string CHANNEL = 'shipping';

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ShipmentCreated::class => 'handleShipmentCreated',
            ShipmentReady::class => 'handleShipmentReady',
            ShipmentDispatched::class => 'handleShipmentDispatched',
            ShipmentDelivered::class => 'handleShipmentDelivered',
            ShipmentReturnedToSender::class => 'handleShipmentReturnedToSender',
            ShipmentLost::class => 'handleShipmentLost',
        ];
    }

    public function handleShipmentCreated(ShipmentCreated $event): void
    {
        $this->log(LogLevel::Info, 'Shipment created.', $this->shipmentContext($event->shipment));
    }

    public function handleShipmentReady(ShipmentReady $event): void
    {
        $this->log(LogLevel::Info, 'Shipment marked ready.', $this->shipmentContext($event->shipment));
    }

    public function handleShipmentDispatched(ShipmentDispatched $event): void
    {
        $this->log(LogLevel::Info, "Shipment dispatched via {$event->shipment->carrier}.", [
                ...$this->shipmentContext($event->shipment),
                'tracking_number' => $event->shipment->tracking_number,
                'tracking_url' => $event->shipment->tracking_url,
            ]);
    }

    public function handleShipmentDelivered(ShipmentDelivered $event): void
    {
        $this->log(LogLevel::Info, 'Shipment delivered.', $this->shipmentContext($event->shipment));
    }

    public function handleShipmentReturnedToSender(ShipmentReturnedToSender $event): void
    {
        $this->log(LogLevel::Warning, 'Shipment returned to sender.', [
                ...$this->shipmentContext($event->shipment),
                'reason' => $event->reason,
            ]);
    }

    public function handleShipmentLost(ShipmentLost $event): void
    {
        $this->log(LogLevel::Error, 'Shipment marked lost.', [
                ...$this->shipmentContext($event->shipment),
                'reason' => $event->reason,
            ]);
    }

    /** @return array<string, mixed> */
    private function shipmentContext(Shipment $shipment): array
    {
        return [
            'shipment_id' => $shipment->id,
            'shippable_type' => $shipment->shippable_type,
            'shippable_id' => $shipment->shippable_id,
            'method' => $shipment->method,
            'carrier' => $shipment->carrier,
            'status' => $shipment->status->value,
        ];
    }
}
