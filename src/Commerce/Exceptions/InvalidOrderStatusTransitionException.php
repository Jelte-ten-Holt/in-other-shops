<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Exceptions;

use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;

final class InvalidOrderStatusTransitionException extends CommerceException
{
    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        return new self("Cannot transition order from {$from->value} to {$to->value}.");
    }

    public static function stockReleased(Order $order): self
    {
        return new self("Cannot confirm order {$order->order_number}: stock reserved for it has been released, so nothing is held for it to ship. Refund or cancel it instead.");
    }
}
