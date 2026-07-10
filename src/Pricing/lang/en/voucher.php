<?php

declare(strict_types=1);

/**
 * Voucher admin strings — `en` source of truth. Domain-specific strings only;
 * recurring field labels (Code, Type, Active) come from `shops-common::fields.*`.
 */
return [
    'section' => [
        'details' => 'Voucher Details',
        'restrictions' => 'Restrictions',
    ],
    'code_help' => 'A random 4-letter suffix will be appended (e.g. SUMMER → SUMMER-KXPQ).',
    'amount' => 'Amount',
    'amount_help_percentage' => 'Admin-friendly percentage (e.g. 10 = 10%). Stored internally as basis points.',
    'amount_help_fixed' => 'Amount in the smallest currency subunit (cents for EUR).',
    'minimum_order_amount' => 'Minimum order amount',
    'minimum_order_amount_help' => 'Minimum order subtotal in cents (0 = no minimum)',
    'max_uses' => 'Max uses',
    'max_uses_placeholder' => 'Unlimited',
    'valid_from' => 'Valid from',
    'valid_from_placeholder' => 'No start date',
    'valid_until' => 'Valid until',
    'valid_until_placeholder' => 'No expiry',
    'uses' => 'Uses',
    'type_options' => [
        'fixed' => 'Fixed amount',
        'percentage' => 'Percentage',
    ],
];
