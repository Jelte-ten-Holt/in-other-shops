<?php

declare(strict_types=1);

/**
 * Purchasing enum labels — `en` source of truth. Mirrors the sentence-case
 * output of `Support\HasLabel` for each PurchaseOrderStatus case.
 */
return [
    'PurchaseOrderStatus' => [
        'draft' => 'Draft',
        'ordered' => 'Ordered',
        'partially_received' => 'Partially received',
        'received' => 'Received',
        'cancelled' => 'Cancelled',
    ],
];
