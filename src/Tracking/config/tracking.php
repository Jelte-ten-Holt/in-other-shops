<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Tracking domain. Each value must
    | be a class that extends the corresponding base model.
    |
    | This is the domain's ONLY config. Tracking deliberately ships no settings
    | of its own — no toggles, no retention policy, no read-surface options —
    | because attribution rows are written by explicitly wired chain steps: a
    | consumer that doesn't want attribution doesn't wire the steps, which is a
    | clearer switch than a config flag. The `models` key is here because it is
    | the package-wide mechanism every model-bearing domain uses to let a
    | consumer swap the class, and the registry resolves through it.
    |
    */

    'models' => [
        'cart_item_attribution' => InOtherShops\Tracking\Models\CartItemAttribution::class,
        'order_line_attribution' => InOtherShops\Tracking\Models\OrderLineAttribution::class,
    ],
];
