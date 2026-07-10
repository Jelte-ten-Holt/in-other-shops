<?php

declare(strict_types=1);

/**
 * Inventory stock admin strings — `en` source of truth. Domain-specific strings
 * only; recurring field labels (Quantity, Description) come from
 * `shops-common::fields.*`.
 */
return [
    'section' => [
        'adjust_stock' => 'Adjust Stock',
        'inventory' => 'Inventory',
    ],
    'fields' => [
        'current_stock' => 'Current stock',
        'low_stock_threshold' => 'Low stock threshold',
        'adjustment_quantity_help' => 'Positive to add, negative to subtract',
        'reason' => 'Reason',
    ],
    'actions' => [
        'movement_history' => 'Movement History',
        'movement_history_heading' => 'Stock Movement History',
    ],
];
