<?php

declare(strict_types=1);

use InOtherShops\Shipping\Models\Shipment;

return [
    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Shipping domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'shipment' => Shipment::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Zones
    |--------------------------------------------------------------------------
    |
    | A zone groups countries that share shipping pricing. Each zone has a
    | currency, an optional free-shipping threshold (in cents, in the zone
    | currency), and a list of ISO 3166-1 alpha-2 country codes.
    |
    | Country codes must be unique across zones — one country belongs to one
    | zone. A country not listed in any zone is unshippable.
    |
    | Example:
    |
    |   'de' => [
    |       'name' => 'Germany',
    |       'currency' => 'EUR',
    |       'countries' => ['DE'],
    |       'free_shipping_threshold' => 5000, // €50.00, null to disable
    |       'sort_order' => 0,
    |   ],
    |
    */

    'zones' => [],

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    |
    | A shipping method is a carrier/service the shop offers (e.g. DHL
    | Standard). Each method has a per-zone rate (cents, in the zone's
    | currency); omitting a zone means the method isn't available there.
    |
    | Example:
    |
    |   'standard' => [
    |       'name' => 'Standard shipping',
    |       'sort_order' => 0,
    |       'is_active' => true,
    |       'rates' => [
    |           'de' => 595,
    |           'eu' => 1499,
    |       ],
    |   ],
    |
    */

    'methods' => [],
];
