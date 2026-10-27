<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Exceptions;

use InOtherShops\Commerce\Order\Models\Order;

/**
 * A paid order was about to change what the customer was charged for — its
 * money fields, lines or addresses — or to be deleted. Money moved for it at
 * those figures, so they are the record of the sale, and the bookkeeping app
 * reads them from the shop (bookkeeping brief §12 P-1).
 */
final class OrderFrozenException extends CommerceException
{
    /** @param  list<string>  $fields */
    public static function fields(Order $order, array $fields): self
    {
        return new self("Order {$order->order_number} is paid; its ".implode(', ', $fields).' can no longer change.');
    }

    public static function lines(Order $order): self
    {
        return new self("Order {$order->order_number} is paid; its lines can no longer change.");
    }

    public static function addresses(Order $order): self
    {
        return new self("Order {$order->order_number} is paid; its addresses can no longer change.");
    }

    public static function deletion(Order $order): self
    {
        return new self("Order {$order->order_number} is paid and cannot be deleted.");
    }
}
