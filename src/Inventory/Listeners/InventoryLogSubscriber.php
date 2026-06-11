<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Listeners;

use InOtherShops\Inventory\Events\ReservationConfirmed;
use InOtherShops\Inventory\Events\ReservationCreated;
use InOtherShops\Inventory\Events\ReservationReleased;
use InOtherShops\Inventory\Events\StockAdjusted;
use InOtherShops\Inventory\Events\StockReleased;
use InOtherShops\Inventory\Events\StockReservationFailed;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogSubscriberBase;
use Illuminate\Contracts\Events\Dispatcher;

final class InventoryLogSubscriber extends LogSubscriberBase
{
    protected const string CHANNEL = 'inventory';

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
        $this->log(LogLevel::Info, "Stock adjusted: {$event->movement->reason->value}.", [
                'stock_item_id' => $event->stockItem->id,
                'stockable_type' => $event->stockItem->stockable_type,
                'stockable_id' => $event->stockItem->stockable_id,
                'quantity' => $event->movement->quantity,
                'stock_level' => $event->stockItem->stock_level,
                'reason' => $event->movement->reason->value,
                'source' => $event->movement->source,
            ]);
    }

    public function handleStockReleased(StockReleased $event): void
    {
        $this->log(LogLevel::Info, 'Stock release posted.', [
                'reservation_id' => $event->reservation->id,
                'reserve_movement_id' => $event->reservation->reserve_movement_id,
                'release_movement_id' => $event->releaseMovement->id,
                'quantity_released' => $event->releaseMovement->quantity,
            ]);
    }

    public function handleStockReservationFailed(StockReservationFailed $event): void
    {
        $this->log(LogLevel::Warning, "Reservation failed: requested {$event->requestedQuantity}, only {$event->availableQuantity} available.", [
                'stockable_type' => $event->stockable->getMorphClass(),
                'stockable_id' => $event->stockable->getKey(),
                'requested_quantity' => $event->requestedQuantity,
                'available_quantity' => $event->availableQuantity,
            ]);
    }

    public function handleReservationCreated(ReservationCreated $event): void
    {
        $this->log(LogLevel::Info, 'Reservation created.', $this->reservationContext($event->reservation));
    }

    public function handleReservationConfirmed(ReservationConfirmed $event): void
    {
        $this->log(LogLevel::Info, 'Reservation confirmed.', $this->reservationContext($event->reservation));
    }

    public function handleReservationReleased(ReservationReleased $event): void
    {
        $this->log(LogLevel::Info, 'Reservation released.', $this->reservationContext($event->reservation));
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
