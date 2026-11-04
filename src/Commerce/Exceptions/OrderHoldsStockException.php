<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Exceptions;

use InOtherShops\Commerce\Order\Models\Order;

/**
 * An order holding stock was about to change its lines (stock-holds brief D6,
 * D13). Its reservations were taken for those lines; changing one without the
 * other desyncs them. Not "frozen": the order may well be unpaid. To change
 * the lines of an unpaid order, delete it and create it again — deleting gives
 * its stock back.
 */
final class OrderHoldsStockException extends CommerceException
{
    public static function lines(Order $order): self
    {
        return new self("Order {$order->order_number} holds stock for its lines, so they can no longer change. Delete the order and create it again to change them.");
    }
}
