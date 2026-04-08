<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Flat Rate Shipping
    |--------------------------------------------------------------------------
    |
    | Default shipping cost in cents. Used by CalculateShippingCost when no
    | specific shipping method is selected.
    |
    */

    'flat_rate' => env('SHIPPING_FLAT_RATE', 595),

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
        'shipment' => InOtherShops\Shipping\Models\Shipment::class,
    ],
];
