<?php

declare(strict_types=1);

/**
 * Stock hold admin strings (stock-holds brief D7) — `en` source of truth.
 */
return [
    'model' => 'stock hold',
    'model_plural' => 'stock holds',
    'nav' => 'Stock holds',

    'fields' => [
        'name' => 'Name',
        'name_help' => 'What the stock is held for, e.g. "Markt 15 Nov" or "Kept for Ana".',
        'held_for' => 'Held for',
        'held_for_help' => 'Optional: the day it is for, such as a market. The hold is flagged once it is open well past this day.',
        'products' => 'Products',
        'products_help' => 'Each product is set to the number you give when you save. Set one to 0, or remove it, to put it back on sale.',
        'product' => 'Product',
    ],
    'columns' => [
        'units' => 'Units',
        'age' => 'Age',
    ],
    'state' => [
        'stale' => 'Open past its day',
        'released' => 'Released',
    ],
    'filters' => [
        'released' => 'Released',
    ],
    'actions' => [
        'add_product' => 'Add product',
        'release' => 'Release',
        'release_modal' => 'Everything this hold still holds goes back on sale, and the hold is closed. It cannot be reopened.',
    ],
    'notifications' => [
        'released' => 'Released :name',
        'refused' => 'Hold not saved',
        'try_again' => 'Someone else was changing the same stock. Nothing was saved — try again.',
    ],
];
