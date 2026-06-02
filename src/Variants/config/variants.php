<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Variants domain. Each value must
    | be a class that extends the corresponding base model. A consumer typically
    | swaps `variant` for an app-level subclass that adds its own purchasable
    | role contract (and HasCart) on top of the package mechanics.
    |
    */

    'models' => [
        'option' => InOtherShops\Variants\Models\Option::class,
        'option_value' => InOtherShops\Variants\Models\OptionValue::class,
        'variant' => InOtherShops\Variants\Models\Variant::class,
    ],
];
