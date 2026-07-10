<?php

declare(strict_types=1);

/**
 * Media schema admin strings — `en` source of truth. Domain-specific strings
 * only; recurring field labels (Type) come from `shops-common::fields.*`.
 */
return [
    'fields' => [
        'path' => 'Path',
        'url' => 'URL',
        'alt' => 'Alt',
        'is_cover' => 'Use as cover image',
        'is_cover_help' => 'The cover image is used in listings and social previews. Only one row across all media collections is kept as the cover.',
    ],
    'embed' => [
        'prompt' => 'Paste a YouTube or Vimeo URL above.',
    ],
    'type_options' => [
        'upload' => 'Upload',
        'external' => 'External URL',
        'embed' => 'Embed',
    ],
];
