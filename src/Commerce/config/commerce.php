<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Commerce domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'cart' => InOtherShops\Commerce\Cart\Models\Cart::class,
        'cart_item' => InOtherShops\Commerce\Cart\Models\CartItem::class,
        'customer' => InOtherShops\Commerce\Customer\Models\Customer::class,
        'customer_group' => InOtherShops\Commerce\Customer\Models\CustomerGroup::class,
        'order' => InOtherShops\Commerce\Order\Models\Order::class,
        'order_line' => InOtherShops\Commerce\Order\Models\OrderLine::class,
    ],
];
