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
    | Override the default models used by the Taxonomy domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'category' => InOtherShops\Taxonomy\Models\Category::class,
        'tag' => InOtherShops\Taxonomy\Models\Tag::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tag types
    |--------------------------------------------------------------------------
    |
    | The vocabulary for `tags.type`. The package stores that column and never
    | interprets it — what a type MEANS is the project's to decide, and one
    | project's vocabulary must not be imposed on another's (a shop has no use
    | for editorial genres, an editorial site none for shop labels).
    |
    | Declare a list here and the admin offers exactly those as a select.
    | Leave it empty — the default — and the field stays free text, which is
    | what every existing consumer keeps on upgrade.
    |
    | Values may be a plain label, or a label with a description shown to the
    | editor so they can tell the types apart without reading code:
    |
    |     'tag_types' => [
    |         'genre' => 'Genre',
    |         'disclosure' => [
    |             'label' => 'Disclosure',
    |             'description' => 'How the work was made, e.g. AI assistance.',
    |         ],
    |     ],
    |
    */

    'tag_types' => [],
];
