<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Tax Mode
    |--------------------------------------------------------------------------
    |
    | Whether stored prices are treated as gross (tax-inclusive) or net
    | (tax-exclusive) when an order doesn't specify a mode. EU B2C shops use
    | 'inclusive' — the displayed price is what the customer pays, with VAT
    | derived from it. 'exclusive' is the B2B/reverse-charge seam (not yet
    | implemented). The mode is resolvable per order; this is the default.
    |
    */

    'default_tax_mode' => 'inclusive',

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

    /*
    |--------------------------------------------------------------------------
    | Scheduled Tasks
    |--------------------------------------------------------------------------
    |
    | The pricing:expire-compare-at command promotes prices whose strikethrough
    | window has closed. It is registered on the Laravel scheduler hourly so a
    | strikethrough ends close to its configured time without anyone having to
    | flip prices by hand. Disable this to run the command yourself.
    |
    */

    'schedule' => [
        'enabled' => true,
    ],
];
