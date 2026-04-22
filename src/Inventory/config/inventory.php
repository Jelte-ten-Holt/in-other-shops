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
    | When enabled, the package registers `inventory:release-expired` on the
    | Laravel scheduler (every 5 minutes). Disable this if you prefer to
    | manage scheduling yourself.
    |
    */

    'schedule' => [
        'enabled' => env('INVENTORY_SCHEDULE_ENABLED', true),
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
