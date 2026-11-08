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
    | not registered. The morph aliases stay, and so do its tables — they just
    | stop growing. Config only; there is no env variable.
    |
    */

    'enabled' => true,

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
        'locale_group' => InOtherShops\Translation\Models\LocaleGroup::class,
    ],
];
