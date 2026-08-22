<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Location domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'address' => InOtherShops\Location\Models\Address::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Country name overrides
    |--------------------------------------------------------------------------
    |
    | InOtherShops\Location\Countries resolves country names from ICU/CLDR, so
    | this package ships NO country data in any language and a consumer's new
    | locale costs nothing. This map is the escape hatch for the handful of
    | cases where ICU's wording is not your shop's wording.
    |
    | Keyed by ISO 3166-1 alpha-2 code, then by locale. Anything absent falls
    | through to ICU, and then to the code itself.
    |
    |   'CZ' => ['es' => 'República Checa'],  // ICU says "Chequia"
    |   'GB' => ['en' => 'Great Britain'],    // ICU says "United Kingdom"
    |
    */

    'country_names' => [],
];
