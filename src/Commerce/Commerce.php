<?php

declare(strict_types=1);

namespace InOtherShops\Commerce;

use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Commerce\Customer\Models\Customer;
use InOtherShops\Commerce\Customer\Models\CustomerGroup;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;

final class Commerce
{
    /** @return class-string<Cart> */
    public static function cart(): string
    {
        return config('commerce.models.cart', Cart::class);
    }

    /** @return class-string<CartItem> */
    public static function cartItem(): string
    {
        return config('commerce.models.cart_item', CartItem::class);
    }

    /** @return class-string<Customer> */
    public static function customer(): string
    {
        return config('commerce.models.customer', Customer::class);
    }

    /** @return class-string<CustomerGroup> */
    public static function customerGroup(): string
    {
        return config('commerce.models.customer_group', CustomerGroup::class);
    }

    /** @return class-string<Order> */
    public static function order(): string
    {
        return config('commerce.models.order', Order::class);
    }

    /** @return class-string<OrderLine> */
    public static function orderLine(): string
    {
        return config('commerce.models.order_line', OrderLine::class);
    }
}
