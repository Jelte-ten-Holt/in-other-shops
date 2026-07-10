<?php

declare(strict_types=1);

/**
 * Purchase order admin strings — `en` source of truth. Domain-specific strings
 * only; recurring field labels (Reference, Status, Notes, Created at) come from
 * `shops-common::fields.*`.
 */
return [
    'section' => [
        'details' => 'Details',
        'lines' => 'Lines',
    ],
    'fields' => [
        'supplier' => 'Supplier',
        'expected_delivery' => 'Expected delivery',
        'shipping_cost' => 'Shipping cost',
        'customs_cost' => 'Customs cost',
    ],
    'columns' => [
        'total' => 'Total',
        'expected' => 'Expected',
    ],
    'actions' => [
        'place' => 'Mark ordered',
        'receive' => 'Receive',
        'cancel' => 'Cancel',
    ],
    'notifications' => [
        'placed' => 'Purchase order placed',
        'nothing_to_receive' => 'Nothing to receive',
        'items_received' => 'Items received',
        'receive_failed' => 'Could not receive items',
        'cancelled' => 'Purchase order cancelled',
        'cancel_failed' => 'Could not cancel',
    ],
];
