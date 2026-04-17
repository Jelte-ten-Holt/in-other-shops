<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Listeners;

use InOtherShops\Inventory\Events\ReservationConfirmed;
use InOtherShops\Inventory\Events\ReservationCreated;
use InOtherShops\Inventory\Events\ReservationReleased;
use InOtherShops\Inventory\Events\StockAdjusted;
use InOtherShops\Inventory\Events\StockReleased;
use InOtherShops\Inventory\Events\StockReservationFailed;
use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogDispatcher;
use Illuminate\Contracts\Events\Dispatcher;

final class InventoryLogSubscriber
{
    private const string CHANNEL = 'inventory';

    public function __construct(
        private readonly LogDispatcher $dispatcher,
    ) {}

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            StockAdjusted::class => 'handleStockAdjusted',
            StockReleased::class => 'handleStockReleased',
            StockReservationFailed::class => 'handleStockReservationFailed',
            ReservationCreated::class => 'handleReservationCreated',
            ReservationConfirmed::class => 'handleReservationConfirmed',
            ReservationReleased::class => 'handleReservationReleased',
        ];
    }

    public function handleStockAdjusted(StockAdjusted $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "Stock adjusted: {$event->movement->reason->value}.",
            context: [
                'stock_item_id' => $event->stockItem->id,
                'stockable_type' => $event->stockItem->stockable_type,
                'stockable_id' => $event->stockItem->stockable_id,
                'quantity' => $event->movement->quantity,
                'stock_level' => $event->stockItem->stock_level,
                'reason' => $event->movement->reason->value,
                'source' => $event->movement->source,
            ],
        ));
    }

    public function handleStockReleased(StockReleased $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Stock release posted.',
            context: [
                'reservation_id' => $event->reservation->id,
                'reserve_movement_id' => $event->reservation->reserve_movement_id,
                'release_movement_id' => $event->releaseMovement->id,
                'quantity_released' => $event->releaseMovement->quantity,
            ],
        ));
    }

    public function handleStockReservationFailed(StockReservationFailed $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Warning,
            channel: self::CHANNEL,
            message: "Reservation failed: requested {$event->requestedQuantity}, only {$event->availableQuantity} available.",
            context: [
                'stockable_type' => $event->stockable->getMorphClass(),
                'stockable_id' => $event->stockable->getKey(),
                'requested_quantity' => $event->requestedQuantity,
                'available_quantity' => $event->availableQuantity,
            ],
        ));
    }

    public function handleReservationCreated(ReservationCreated $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Reservation created.',
            context: $this->reservationContext($event->reservation),
        ));
    }

    public function handleReservationConfirmed(ReservationConfirmed $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Reservation confirmed.',
            context: $this->reservationContext($event->reservation),
        ));
    }

    public function handleReservationReleased(ReservationReleased $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Reservation released.',
            context: $this->reservationContext($event->reservation),
        ));
    }

    /** @return array<string, mixed> */
    private function reservationContext(\InOtherShops\Inventory\Models\StockReservation $reservation): array
    {
        return [
            'reservation_id' => $reservation->id,
            'stock_item_id' => $reservation->stock_item_id,
            'quantity' => $reservation->quantity,
            'status' => $reservation->status->value,
            'reference_type' => $reservation->reference_type,
            'reference_id' => $reservation->reference_id,
        ];
    }
}
