<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Listeners;

use InOtherShops\Commerce\Order\Actions\ReleaseOrderStock;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Events\OrderStatusChanged;
use InOtherShops\Inventory\Actions\ConfirmReservation;

/**
 * Default OrderStatusChanged handler. Drives the inventory side-effects of
 * an order status transition so every path that mutates status — payment
 * webhook, admin Filament action, future refund/return flows — gets the
 * same inventory behavior automatically.
 *
 * Transitions:
 * - Pending → Confirmed: confirms all pending reservations for the order.
 * - * → Cancelled:       gives back all pending or confirmed reservations for the
 *                        order through {@see ReleaseOrderStock}: units an admin
 *                        order took from a hold that is still open go back to
 *                        it (stock-holds brief D10); the rest are released —
 *                        for a checkout order, all of them, as before.
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
        private readonly ReleaseOrderStock $releaseOrderStock,
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
            ($this->releaseOrderStock)($order);
        }
    }
}
