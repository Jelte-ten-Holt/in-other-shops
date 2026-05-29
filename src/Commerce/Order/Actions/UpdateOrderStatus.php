<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use Illuminate\Support\Facades\DB;
use InOtherShops\Commerce\Exceptions\InvalidOrderStatusTransitionException;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Events\OrderStatusChanged;
use InOtherShops\Commerce\Order\Models\Order;

final class UpdateOrderStatus
{
    /**
     * The status write, the event, and the synchronous listeners it triggers
     * (inventory sync, log subscriber) all run inside one transaction. A
     * status transition is terminal — `Cancelled` has no outgoing transitions —
     * so if a listener threw after the status had already committed, the order
     * would be stuck in the new status with its inventory side-effects half
     * applied and no retry path (the retry would fail `validateTransition`).
     * Wrapping the dispatch means any listener failure rolls the status back to
     * a recoverable state instead. See audit finding C-1.
     */
    public function __invoke(Order $order, OrderStatus $newStatus): Order
    {
        $this->validateTransition($order, $newStatus);

        $oldStatus = $order->status;

        DB::transaction(function () use ($order, $oldStatus, $newStatus): void {
            $order->update(['status' => $newStatus]);

            OrderStatusChanged::dispatch($order, $oldStatus, $newStatus);
        });

        return $order;
    }

    private function validateTransition(Order $order, OrderStatus $newStatus): void
    {
        if (! $order->status->canTransitionTo($newStatus)) {
            throw InvalidOrderStatusTransitionException::between($order->status, $newStatus);
        }
    }
}
