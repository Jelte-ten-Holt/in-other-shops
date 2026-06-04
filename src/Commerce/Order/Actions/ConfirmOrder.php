<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use InOtherShops\Commerce\Order\Enums\ConfirmOrderOutcome;
use InOtherShops\Commerce\Order\Events\OrderConfirmationBlocked;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Inventory\Actions\ConfirmReservation;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;
use Illuminate\Support\Facades\DB;

/**
 * Idempotently confirm a paid order, exactly once, with the stock guard that F14
 * was missing. Locks the order so it serialises against order-expiry (P3) and
 * any racing confirmation:
 *
 *  - already Confirmed → {@see ConfirmOrderOutcome::AlreadyConfirmed} (the
 *    redelivery / double-event case — the caller must NOT re-send the buyer's
 *    confirmation email or re-clear the cart; that was the real F2/F3 bug);
 *  - not Pending (e.g. Cancelled by order-expiry) → flagged + `NotConfirmable`;
 *  - Pending but its reservations were already released (F14 — the cron pulled
 *    the stock back while payment was in flight) → flagged + `StockUnavailable`,
 *    NOT silently confirmed against no held stock;
 *  - Pending with stock still held → confirm reservations, transition to
 *    Confirmed, `Confirmed`.
 *
 * The flagged cases dispatch {@see OrderConfirmationBlocked} so a paid-but-
 * unfulfillable order is audited and an operator can restock+confirm or refund.
 */
final class ConfirmOrder
{
    public function __construct(
        private readonly ConfirmReservation $confirmReservation,
        private readonly UpdateOrderStatus $updateOrderStatus,
    ) {}

    public function __invoke(Order $order): ConfirmOrderOutcome
    {
        return DB::transaction(function () use ($order): ConfirmOrderOutcome {
            $locked = $order->newQuery()->lockForUpdate()->find($order->getKey());

            if ($locked === null) {
                return ConfirmOrderOutcome::NotConfirmable;
            }

            if ($locked->status === OrderStatus::Confirmed) {
                $order->setRawAttributes($locked->getAttributes(), true);

                return ConfirmOrderOutcome::AlreadyConfirmed;
            }

            if ($locked->status !== OrderStatus::Pending) {
                OrderConfirmationBlocked::dispatch($locked, "payment succeeded but order is {$locked->status->value}");

                return ConfirmOrderOutcome::NotConfirmable;
            }

            if ($this->stockWasReleased($locked)) {
                OrderConfirmationBlocked::dispatch($locked, 'stock reservations were released before payment confirmed');

                return ConfirmOrderOutcome::StockUnavailable;
            }

            ($this->confirmReservation)($locked, 'Order confirmed on payment success');
            ($this->updateOrderStatus)($locked, OrderStatus::Confirmed);
            $order->setRawAttributes($locked->getAttributes(), true);

            return ConfirmOrderOutcome::Confirmed;
        });
    }

    /**
     * True when the order held stock that is no longer held — it has reservation
     * rows but none are active (Pending/Confirmed), i.e. they were all Released.
     * An order with NO reservations at all never held stock (e.g. digital goods)
     * and is fine to confirm.
     */
    private function stockWasReleased(Order $order): bool
    {
        $model = Inventory::stockReservation();

        $base = fn () => $model::query()
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->getKey());

        if (! $base()->exists()) {
            return false;
        }

        $hasActive = $base()
            ->whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value])
            ->exists();

        return ! $hasActive;
    }
}
