<?php

declare(strict_types=1);

return [
    'models' => [
        'products' => \App\Models\Product::class,
    ],

    'defaults' => [
        'currency' => 'EUR',
        'per_page' => 24,
    ],

    'prefix' => 'storefront',

    'middleware' => ['api'],
];
