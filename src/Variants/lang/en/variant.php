<?php

declare(strict_types=1);

/**
 * Variant editor admin strings — `en` source of truth. Domain-specific strings
 * only; recurring field labels (SKU, Price) come from `shops-common::fields.*`.
 */
return [
    'axes' => [
        'label' => 'Varies by',
        'help' => 'The attributes this product varies by. Add values to each option in the Options catalog.',
    ],
    'repeater' => [
        'label' => 'Variants',
        'price' => 'Price (:currency)',
        'price_help' => 'Leave blank to keep the current price.',
        'stock' => 'Stock',
    ],
];
