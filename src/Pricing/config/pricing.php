<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Domain switch
    |--------------------------------------------------------------------------
    |
    | false switches the whole domain off: no migrations, translations or
    | views load, its log subscriber, listeners, commands and schedules are
    | not registered (an app schedule line naming one of its commands then
    | fails). The morph aliases and its tables stay. Meant for a domain the
    | shop does not use, such as Purchasing: a domain the enabled ones build
    | on (Translation, Location, Media, Logging, Taxonomy) is not safe to
    | switch off. Config only; there is no env variable.
    |
    */

    'enabled' => true,

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
