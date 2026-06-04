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
        'refund' => InOtherShops\Commerce\Order\Models\Refund::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Order
    |--------------------------------------------------------------------------
    |
    | `number_generator` must implement OrderNumberGenerator. Default is
    | a random-suffix generator; swap in a sequential one when you need
    | human-friendly sequences. `number_prefix` is used by the default.
    |
    */

    'order' => [
        'number_prefix' => env('ORDER_NUMBER_PREFIX', 'ORD'),
        'number_generator' => InOtherShops\Commerce\Order\Support\RandomOrderNumberGenerator::class,

        // How long a Pending (unpaid) order is held before `commerce:expire-orders`
        // cancels it — releasing its reservations and cancelling the gateway
        // intent so a late payment can't land on it (F14). Must comfortably
        // exceed the reservation TTL plus a real payment window.
        'abandon_after_minutes' => (int) env('ORDER_ABANDON_AFTER_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cart API
    |--------------------------------------------------------------------------
    |
    | Optional REST endpoints for the cart domain. Disable when a consumer
    | drives the cart in-process (e.g. Livewire) and does not need HTTP.
    |
    | The default middleware stack is ["web"] because guest cart resolution
    | reads `session()->getId()` — a stateless API consumer should swap in
    | their own auth/session middleware (e.g. sanctum stateful) and may also
    | need a `cartables` map for non-default morph aliases.
    |
    */

    'cart' => [
        'api' => [
            'enabled' => false,
            'prefix' => 'api/cart',
            'middleware' => ['web'],
            'default_currency' => 'EUR',
        ],

        /*
        | Block deleting any cart-able model (Product, Bundle, Variant, …) while
        | a live cart still references it, preventing stranded cart lines. Set
        | to false to allow deletion regardless (order lines snapshot, so order
        | history is never affected either way).
        */
        'guard_cartable_deletion' => true,
    ],
];
