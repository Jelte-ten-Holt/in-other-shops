<?php

declare(strict_types=1);

namespace InOtherShops\Tracking;

use InOtherShops\Tracking\Models\CartItemAttribution;
use InOtherShops\Tracking\Models\OrderLineAttribution;

final class Tracking
{
    /** @return class-string<CartItemAttribution> */
    public static function cartItemAttribution(): string
    {
        return config('tracking.models.cart_item_attribution', CartItemAttribution::class);
    }

    /** @return class-string<OrderLineAttribution> */
    public static function orderLineAttribution(): string
    {
        return config('tracking.models.order_line_attribution', OrderLineAttribution::class);
    }
}
