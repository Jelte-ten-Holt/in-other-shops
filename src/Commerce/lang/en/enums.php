<?php

declare(strict_types=1);

/**
 * Commerce enum admin labels — `en` source of truth. Resolved by
 * {@see \InOtherShops\Support\HasLabel} as `shops-commerce::enums.{Enum}.{value}`.
 * `en` values equal the sentence-case fallback (backing value, underscores to
 * spaces, first letter upper-cased).
 */
return [
    'OrderStatus' => [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
    ],
    'RefundActorSource' => [
        'admin' => 'Admin',
        'gateway' => 'Gateway',
    ],
];
