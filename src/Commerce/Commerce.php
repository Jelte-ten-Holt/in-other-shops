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
    public static function cart(): Cart
    {
        $class = config('commerce.models.cart', Cart::class);

        return new $class;
    }

    public static function cartItem(): CartItem
    {
        $class = config('commerce.models.cart_item', CartItem::class);

        return new $class;
    }

    public static function customer(): Customer
    {
        $class = config('commerce.models.customer', Customer::class);

        return new $class;
    }

    public static function customerGroup(): CustomerGroup
    {
        $class = config('commerce.models.customer_group', CustomerGroup::class);

        return new $class;
    }

    public static function order(): Order
    {
        $class = config('commerce.models.order', Order::class);

        return new $class;
    }

    public static function orderLine(): OrderLine
    {
        $class = config('commerce.models.order_line', OrderLine::class);

        return new $class;
    }
}
