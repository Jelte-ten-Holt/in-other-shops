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
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Tracking domain. Each value must
    | be a class that extends the corresponding base model.
    |
    | Tracking ships no settings of its own beyond the domain switch above —
    | no retention policy, no read-surface options. Attribution rows are written
    | by explicitly wired chain steps, so a consumer that doesn't want
    | attribution doesn't wire them; `enabled => false` additionally makes a
    | step still listed in a published chain write nothing. The `models` key is
    | the package-wide mechanism every model-bearing domain uses to let a
    | consumer swap the class, and the registry resolves through it.
    |
    */

    'models' => [
        'cart_item_attribution' => InOtherShops\Tracking\Models\CartItemAttribution::class,
        'order_line_attribution' => InOtherShops\Tracking\Models\OrderLineAttribution::class,
    ],
];
