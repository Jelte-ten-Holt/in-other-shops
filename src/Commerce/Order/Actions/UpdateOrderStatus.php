<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use InOtherShops\Commerce\Exceptions\InvalidOrderStatusTransitionException;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Events\OrderStatusChanged;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Support\Concerns\RunsLockedTransactions;

final class UpdateOrderStatus
{
    use RunsLockedTransactions;

    /**
     * The status write, the event, and the synchronous listeners it triggers
     * (inventory sync, log subscriber) all run inside one transaction. A
     * status transition is terminal — `Cancelled` has no outgoing transitions —
     * so if a listener threw after the status had already committed, the order
     * would be stuck in the new status with its inventory side-effects half
     * applied and no retry path (the retry would fail `validateTransition`).
     * Wrapping the dispatch means any listener failure rolls the status back to
     * a recoverable state instead. See audit finding C-1.
     *
     * The row is locked and re-read inside the transaction so concurrent callers
     * serialize: the second waits for the first to commit, then sees the new
     * status. If that status already equals the target it is an idempotent
     * no-op (no second event), so two racing callers — an admin double-submit,
     * or an admin clicking confirm as a webhook lands — dispatch
     * `OrderStatusChanged` exactly once rather than double-logging and
     * double-firing side effects. See audit finding C-2. (The webhook path is
     * already idempotent at the Payment layer via the `webhook_events` ledger;
     * this guards every other caller.)
     */
    public function __invoke(Order $order, OrderStatus $newStatus): Order
    {
        return $this->withLocked($order, function (?Order $locked) use ($order, $newStatus): Order {
            if ($locked === null) {
                return $order;
            }

            $currentStatus = $locked->status;

            if ($currentStatus === $newStatus) {
                // Already there — a racing caller beat us to it. Sync the
                // caller's instance and return without re-dispatching.
                $order->setRawAttributes($locked->getAttributes(), true);

                return $order;
            }

            $this->validateTransition($currentStatus, $newStatus);

            $locked->update(['status' => $newStatus]);
            $order->setRawAttributes($locked->getAttributes(), true);

            OrderStatusChanged::dispatch($order, $currentStatus, $newStatus);

            return $order;
        });
    }

    private function validateTransition(OrderStatus $currentStatus, OrderStatus $newStatus): void
    {
        if (! $currentStatus->canTransitionTo($newStatus)) {
            throw InvalidOrderStatusTransitionException::between($currentStatus, $newStatus);
        }
    }
}
