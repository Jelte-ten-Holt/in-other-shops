<?php

declare(strict_types=1);
use InOtherShops\Tax\Models\TaxRate;

return [
    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Tax domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'tax_rate' => TaxRate::class,
    ],
];
