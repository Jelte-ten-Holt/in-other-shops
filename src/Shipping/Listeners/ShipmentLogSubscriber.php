<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogDispatcher;
use InOtherShops\Shipping\Events\ShipmentCreated;
use InOtherShops\Shipping\Events\ShipmentDelivered;
use InOtherShops\Shipping\Events\ShipmentDispatched;
use InOtherShops\Shipping\Events\ShipmentLost;
use InOtherShops\Shipping\Events\ShipmentReady;
use InOtherShops\Shipping\Events\ShipmentReturnedToSender;
use InOtherShops\Shipping\Models\Shipment;

final class ShipmentLogSubscriber
{
    private const string CHANNEL = 'shipping';

    public function __construct(
        private readonly LogDispatcher $dispatcher,
    ) {}

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
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Shipment created.',
            context: $this->shipmentContext($event->shipment),
        ));
    }

    public function handleShipmentReady(ShipmentReady $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Shipment marked ready.',
            context: $this->shipmentContext($event->shipment),
        ));
    }

    public function handleShipmentDispatched(ShipmentDispatched $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "Shipment dispatched via {$event->shipment->carrier}.",
            context: [
                ...$this->shipmentContext($event->shipment),
                'tracking_number' => $event->shipment->tracking_number,
                'tracking_url' => $event->shipment->tracking_url,
            ],
        ));
    }

    public function handleShipmentDelivered(ShipmentDelivered $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Shipment delivered.',
            context: $this->shipmentContext($event->shipment),
        ));
    }

    public function handleShipmentReturnedToSender(ShipmentReturnedToSender $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Warning,
            channel: self::CHANNEL,
            message: 'Shipment returned to sender.',
            context: [
                ...$this->shipmentContext($event->shipment),
                'reason' => $event->reason,
            ],
        ));
    }

    public function handleShipmentLost(ShipmentLost $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Error,
            channel: self::CHANNEL,
            message: 'Shipment marked lost.',
            context: [
                ...$this->shipmentContext($event->shipment),
                'reason' => $event->reason,
            ],
        ));
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
