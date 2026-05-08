<?php

declare(strict_types=1);
use InOtherShops\Tax\Models\TaxRate;

return [
    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Tax domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'tax_rate' => TaxRate::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Jurisdictions
    |--------------------------------------------------------------------------
    |
    | A jurisdiction groups countries that share a VAT/sales-tax framework.
    | The shipped default covers the EU 27. Override or add jurisdictions in
    | the consuming project's config when needed (Brexit-style events,
    | adding DACH, etc.).
    |
    */

    'jurisdictions' => [
        'eu' => [
            'name' => 'European Union',
            'countries' => [
                'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
                'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
                'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Home jurisdiction
    |--------------------------------------------------------------------------
    |
    | The jurisdiction the seller operates under. When set:
    |
    |   - countries inside this jurisdiction without a specific TaxRate row
    |     fall back to the `is_default` row (the seller's home rate);
    |   - countries outside this jurisdiction return the `export_rate` below
    |     (zero-rated export) instead of the default.
    |
    | Leave null to preserve pre-jurisdiction behavior (any unmapped country
    | falls back to `is_default`, which becomes a global catch-all).
    |
    */

    'home_jurisdiction' => null,

    /*
    |--------------------------------------------------------------------------
    | Export rate
    |--------------------------------------------------------------------------
    |
    | Synthetic rate returned by ResolveTaxRate when the destination is
    | outside `home_jurisdiction`. The default (0 bps, "Zero-rated export")
    | matches EU rules for goods exported outside the EU. Override per shop
    | if a customer's country has a special arrangement.
    |
    */

    'export_rate' => [
        'rate_bps' => 0,
        'name' => 'Zero-rated export',
    ],
];
