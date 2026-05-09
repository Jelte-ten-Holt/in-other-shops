<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Browsable Models
    |--------------------------------------------------------------------------
    |
    | Map of storefront key → consumer model class. Each model must implement
    | HasStorefrontPresence. Consumers populate this — the package ships no
    | defaults because it has no knowledge of consumer FQCNs. The Agent
    | module's catalog tools (BrowseCatalog, ShowBrowsable, etc.) and the
    | Storefront Actions read this map to resolve type → class.
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
        'per_page' => 24,
    ],
];
