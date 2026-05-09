<?php

declare(strict_types=1);

use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Shipping\Models\ShipmentItem;

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
        'shipment_item' => ShipmentItem::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-create Shipment on OrderCreated
    |--------------------------------------------------------------------------
    |
    | When true, the package's default listener creates a single Pending
    | Shipment for every newly-created Order, covering all of its order
    | lines. Disable to compose Shipments yourself (e.g. split warehouse
    | routing).
    |
    */

    'auto_create_shipment' => true,

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

    /*
    |--------------------------------------------------------------------------
    | Carriers
    |--------------------------------------------------------------------------
    |
    | Carrier identifier => display name + tracking-URL template. The
    | identifier is what's stored on Shipment.carrier; consumers may use
    | a free-form string instead (a one-off carrier with a non-templatable
    | URL passes its tracking URL explicitly to DispatchShipment).
    |
    | The {tracking_number} placeholder is substituted at dispatch time.
    |
    | Example:
    |
    |   'dhl' => [
    |       'name' => 'DHL',
    |       'tracking_url_template' => 'https://nolp.dhl.de/nextt-online-public/en/search?piececode={tracking_number}',
    |   ],
    |
    */

    'carriers' => [],
];
