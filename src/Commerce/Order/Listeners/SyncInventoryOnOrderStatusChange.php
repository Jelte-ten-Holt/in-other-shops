<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Listeners;

use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Events\OrderStatusChanged;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Inventory\Actions\ConfirmReservation;
use InOtherShops\Inventory\Actions\ReleaseReservation;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;

/**
 * Default OrderStatusChanged handler. Drives the inventory side-effects of
 * an order status transition so every path that mutates status — payment
 * webhook, admin Filament action, future refund/return flows — gets the
 * same inventory behavior automatically.
 *
 * Transitions:
 * - Pending → Confirmed: confirms all pending reservations for the order.
 * - * → Cancelled:       releases all pending or confirmed reservations for the order.
 *
 * Both Inventory actions are status-guarded and lock-for-update, so running
 * alongside a consumer listener that also calls them (e.g. an existing
 * HandlePaymentSucceeded / HandlePaymentFailed that explicitly confirms or
 * releases before advancing status) is safe — the second call is a no-op.
 */
final class SyncInventoryOnOrderStatusChange
{
    public function __construct(
        private readonly ConfirmReservation $confirmReservation,
        private readonly ReleaseReservation $releaseReservation,
    ) {}

    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;
        $reason = "Order status changed: {$event->from->value} → {$event->to->value}";

        if ($event->from === OrderStatus::Pending && $event->to === OrderStatus::Confirmed) {
            ($this->confirmReservation)($order, $reason);

            return;
        }

        if ($event->to === OrderStatus::Cancelled) {
            $this->releaseReservationsFor($order);
        }
    }

    private function releaseReservationsFor(Order $order): void
    {
        $model = Inventory::stockReservation();

        $reservations = $model::query()
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->getKey())
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->get();

        foreach ($reservations as $reservation) {
            ($this->releaseReservation)($reservation);
        }
    }
}
