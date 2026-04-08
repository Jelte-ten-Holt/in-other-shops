<?php

declare(strict_types=1);

return [
    'locales' => ['en'],
    'default' => 'en',
    'fallback' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Translation domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'translation' => InOtherShops\Translation\Models\Translation::class,
    ],
];
