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
    | Purchase Order Reference Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix used when CreatePurchaseOrder auto-generates a reference for a new
    | purchase order (e.g. "PO-7F3K9QX2"). Callers may pass an explicit
    | reference to override generation entirely.
    |
    */

    'reference_prefix' => env('PURCHASING_REFERENCE_PREFIX', 'PO'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Purchasing domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'supplier' => InOtherShops\Purchasing\Models\Supplier::class,
        'purchase_order' => InOtherShops\Purchasing\Models\PurchaseOrder::class,
        'purchase_order_line' => InOtherShops\Purchasing\Models\PurchaseOrderLine::class,
    ],
];
