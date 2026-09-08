<?php

declare(strict_types=1);

return [
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
    */

    'reservation_ttl' => env('INVENTORY_RESERVATION_TTL', 30),

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
