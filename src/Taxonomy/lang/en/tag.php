<?php

declare(strict_types=1);

/**
 * Tag admin strings — `en` source of truth. Domain-specific strings only;
 * recurring field labels (Name, Slug, Type, Position, Active) come from
 * `shops-common::fields.*`.
 */
return [
    'model' => 'tag',
    'model_plural' => 'tags',
    'nav' => 'Tags',
    'relation_title' => 'Tags',

    'section' => [
        'details' => 'Tag Details',
    ],
    'fields' => [
        'type_placeholder' => 'e.g. color, material, season',
    ],
];
