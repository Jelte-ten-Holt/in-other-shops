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

        /*
        | OrderSummary presenter. `shows_vat` decides whether a VAT line
        | exists on shopper-facing order summaries at all — a shop that
        | charges no VAT (e.g. German §19 Kleinunternehmer) sets false, since
        | on its invoices the line would be actively wrong. Default true: the
        | ordinary EU B2C shop shows included VAT.
        */
        'summary' => [
            'shows_vat' => true,
        ],

        // How long a Pending (unpaid) order is held before `commerce:expire-orders`
        // cancels it — releasing its reservations and cancelling the gateway
        // intent so a late payment can't land on it (F14).
        //
        // Must be SHORTER than `inventory.reservation_ttl`, not longer. The
        // reservation has to outlive the window in which the order can still be
        // paid: get it the other way round and every checkout gets an interval
        // where `inventory:release-expired` has already returned the stock while
        // the gateway intent is still live — a late payment is captured,
        // ConfirmOrder rejects it for missing stock, and `commerce:expire-orders`
        // then refuses to cancel an order that has a succeeded payment. Money
        // taken, order Pending forever. With the invariant held,
        // `commerce:expire-orders` is the single actor that ends a Pending order
        // and the reservation never lapses on its own.
        //
        // (This comment said the opposite until v0.71.0; bianka reported it
        // 2026-07-17 and pins the real invariant in ReservationTtlInvariantTest.)
        //
        // ⚠ The package's own SHIPPED DEFAULTS violate this: 60 here against an
        // `inventory.reservation_ttl` default of 30. Both consumers override the
        // TTL to 90, so no live shop is exposed — but a fresh consumer taking
        // both defaults is. Correcting the default is a behaviour change and is
        // deliberately not part of this release.
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
        ],

        /*
        | How long an untouched GUEST cart lives before `commerce:prune-carts`
        | deletes it (days). Stamped on guest-cart creation and slid forward on
        | every cart write, so an actively-used cart never expires under the
        | shopper. Owner (logged-in) carts are not stamped and are never pruned.
        */
        'ttl_days' => (int) env('CART_TTL_DAYS', 30),

        /*
        | Block deleting any cart-able model (Product, Bundle, Variant, …) while
        | a live cart still references it, preventing stranded cart lines. Set
        | to false to allow deletion regardless (order lines snapshot, so order
        | history is never affected either way).
        */
        'guard_cartable_deletion' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    |
    | Quote-side checkout pieces (QuoteCheckout, the voucher apply/remove
    | endpoints). The voucher routes are NEVER auto-registered — the consumer
    | mounts CheckoutRoutes::voucher() inside its own (localized) route group.
    | The checkout CHAIN itself stays consumer-owned.
    |
    */

    'checkout' => [
        'voucher' => [
            // Where the shopper's applied code lives between form and submit.
            'session_key' => 'checkout.voucher_code',

            // Route the apply/remove controllers redirect back to.
            'redirect_route' => 'checkout.index',

            // The apply endpoint answers "is this a real code?" — the IP-keyed
            // limiter is the only thing stopping a code-space walk. Generous
            // for typing, useless for enumeration.
            'rate_limit' => [
                'max_attempts' => 5,
                'decay_seconds' => 60,
            ],
        ],
    ],
];
