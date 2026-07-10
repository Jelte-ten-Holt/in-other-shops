<?php

declare(strict_types=1);

/**
 * Inventory enum admin labels — `en` source of truth. Resolved by
 * Support\HasLabel via `shops-inventory::enums.{Enum}.{value}`. `en` mirrors the
 * enum's sentence-case fallback verbatim; keys are the backing values.
 */
return [
    'ReservationStatus' => [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'released' => 'Released',
    ],
    'StockMovementReason' => [
        'received' => 'Received',
        'restock' => 'Restock',
        'sold' => 'Sold',
        'reserved' => 'Reserved',
        'released' => 'Released',
        'adjusted' => 'Adjusted',
    ],
];
