<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Enabled Currencies
    |--------------------------------------------------------------------------
    |
    | Restrict the available currencies to a subset of the Currency enum.
    | Set to null to allow all currencies defined in the enum.
    | Set to an array of ISO 4217 codes (e.g. ['EUR']) to restrict.
    |
    */
    'currencies' => null,

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Pricing domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'price' => InOtherShops\Pricing\Models\Price::class,
        'price_list' => InOtherShops\Pricing\Models\PriceList::class,
        'voucher' => InOtherShops\Pricing\Models\Voucher::class,
    ],
];
