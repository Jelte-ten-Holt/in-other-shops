<?php

declare(strict_types=1);

/**
 * Shipping enum admin labels — `en` source of truth. Keyed by the enum's
 * backing value; resolved by `Support\HasLabel::defaultLabel()` via
 * `shops-shipping::enums.{Enum}.{value}`.
 */
return [
    'ShipmentStatus' => [
        'pending' => 'Pending',
        'ready' => 'Ready',
        'in_transit' => 'In transit',
        'delivered' => 'Delivered',
        'returned_to_sender' => 'Returned to sender',
        'lost' => 'Lost',
    ],
];
