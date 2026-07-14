<?php

declare(strict_types=1);

/**
 * Shipment admin strings — `en` source of truth. Domain-specific strings only;
 * recurring field labels (Status) come from `shops-common::fields.*`.
 */
return [
    'title' => 'Shipments',

    'columns' => [
        'method' => 'Method',
        'carrier' => 'Carrier',
        'tracking' => 'Tracking',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'created' => 'Created',
    ],
    'form' => [
        'carrier' => 'Carrier',
        'tracking_number' => 'Tracking number',
        'tracking_url' => 'Tracking URL',
        'tracking_url_help' => 'Leave blank to derive from the carrier template (config/shipping.carriers).',
        'reason' => 'Reason',
    ],
    'actions' => [
        'mark_ready' => 'Mark ready',
        'dispatch' => 'Dispatch',
        'mark_delivered' => 'Mark delivered',
        'mark_returned_to_sender' => 'Mark returned to sender',
        'mark_lost' => 'Mark lost',
    ],
    'notifications' => [
        'marked_ready' => 'Shipment marked ready',
        'cannot_dispatch' => 'Shipment cannot be dispatched',
        'cannot_dispatch_body' => 'The associated payable is not paid in full.',
        'dispatched' => 'Shipment dispatched',
        'marked_delivered' => 'Shipment marked delivered',
        'marked_returned_to_sender' => 'Shipment marked returned to sender',
        'marked_lost' => 'Shipment marked lost',
    ],
];
