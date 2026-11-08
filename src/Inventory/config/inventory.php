<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Domain switch
    |--------------------------------------------------------------------------
    |
    | false switches the whole domain off: no migrations, translations or
    | views load, its log subscriber, listeners, commands and schedules are
    | not registered. The morph aliases stay, and so do its tables — they just
    | stop growing. Config only; there is no env variable.
    |
    */

    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Stock Movement Sources
    |--------------------------------------------------------------------------
    |
    | Valid sources for stock movements as key => label pairs. When configured,
    | AdjustStock validates the source parameter against these keys. Leave
    | empty or null to allow any source value.
    |
    */

    'sources' => [
        'dashboard' => 'Dashboard',
        'checkout' => 'Checkout',
        'import' => 'Import',
        'agent' => 'Agent API',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reservation TTL
    |--------------------------------------------------------------------------
    |
    | Default reservation TTL in minutes. Used when ReserveStock is called
    | without an explicit reservedUntil timestamp. Set to null to disable
    | automatic TTL.
    |
    | Must be LONGER than `commerce.order.abandon_after_minutes` (default 60):
    | a reservation has to outlive the window in which its order can still be
    | paid. Commerce refuses to boot otherwise (ReservationTtlTooShortException).
    | The default was 30 — shorter than the package's own abandon window —
    | until release 2 (X6b).
    |
    */

    'reservation_ttl' => env('INVENTORY_RESERVATION_TTL', 120),

    /*
    |--------------------------------------------------------------------------
    | Stale holds
    |--------------------------------------------------------------------------
    |
    | Days a stock hold may stay open past its day (`held_for`, else its
    | creation), and an unpaid admin order may hold stock past its creation,
    | before it is flagged: a badge on the Stock holds page and the orders
    | list, and a warning line in `inventory:reconcile`. A warning only — it
    | never counts as drift, never fails the nightly run and never fires
    | InventoryDriftDetected.
    |
    */

    'hold_warn_after_days' => env('INVENTORY_HOLD_WARN_AFTER_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Scheduled Commands
    |--------------------------------------------------------------------------
    |
    | When enabled, the package registers two commands on the Laravel
    | scheduler: `inventory:release-expired` (every 5 minutes, fixed) and the
    | read-only tripwire `inventory:reconcile` (cron below, daily by default).
    | The tripwire dispatches `InventoryDriftDetected` when it finds drift —
    | subscribe to that if you want an alert. One switch turns both off if you
    | prefer to manage scheduling yourself.
    |
    */

    'schedule' => [
        'enabled' => env('INVENTORY_SCHEDULE_ENABLED', true),
        'reconcile' => env('INVENTORY_RECONCILE_CRON', '0 3 * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Inventory domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'stock_item' => InOtherShops\Inventory\Models\StockItem::class,
        'stock_movement' => InOtherShops\Inventory\Models\StockMovement::class,
        'stock_reservation' => InOtherShops\Inventory\Models\StockReservation::class,
    ],
];
