<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use InOtherShops\Commerce\Exceptions\InvalidOrderStatusTransitionException;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Events\OrderStatusChanged;
use InOtherShops\Commerce\Order\Models\Order;

final class UpdateOrderStatus
{
    public function __invoke(Order $order, OrderStatus $newStatus): Order
    {
        $this->validateTransition($order, $newStatus);

        $oldStatus = $order->status;

        $order->update(['status' => $newStatus]);

        OrderStatusChanged::dispatch($order, $oldStatus, $newStatus);

        return $order;
    }

    private function validateTransition(Order $order, OrderStatus $newStatus): void
    {
        if (! $order->status->canTransitionTo($newStatus)) {
            throw InvalidOrderStatusTransitionException::between($order->status, $newStatus);
        }
    }
}
