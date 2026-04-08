<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Location domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'address' => InOtherShops\Location\Models\Address::class,
    ],
];
