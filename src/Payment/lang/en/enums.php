<?php

declare(strict_types=1);

/**
 * Payment enum labels — `en` source of truth. Mirrors the sentence-case labels
 * produced by the `Support\HasLabel` trait; the enum is not edited to consume
 * these, so keys are the backing string values.
 */
return [
    'PaymentStatus' => [
        'pending' => 'Pending',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
        'refunded' => 'Refunded',
        'partially_refunded' => 'Partially refunded',
    ],
];
