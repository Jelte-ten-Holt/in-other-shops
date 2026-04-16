<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Browsable Models
    |--------------------------------------------------------------------------
    |
    | Map of storefront route key → consumer model class. Each model must
    | implement HasStorefrontPresence. Consumers populate this — the package
    | ships no defaults because it has no knowledge of consumer FQCNs.
    |
    | Example (consumer config/storefront.php):
    |
    |     'models' => [
    |         'products' => App\Models\Product::class,
    |         'bundles'  => App\Models\Bundle::class,
    |     ],
    |
    */

    'models' => [],

    'defaults' => [
        'currency' => 'EUR',
        'per_page' => 24,
    ],

    'prefix' => 'storefront',

    'middleware' => ['api'],
];
