<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Taxonomy domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'category' => InOtherShops\Taxonomy\Models\Category::class,
        'tag' => InOtherShops\Taxonomy\Models\Tag::class,
    ],
];
