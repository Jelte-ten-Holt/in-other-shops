<?php

declare(strict_types=1);

/**
 * Option catalog admin strings — `en` source of truth. Domain-specific strings
 * only; recurring field labels (Name, Slug, Position, Code) come from
 * `shops-common::fields.*`.
 */
return [
    'section' => [
        'option' => 'Option',
        'values' => 'Values',
    ],
    'repeater' => [
        'values_label' => 'Values',
    ],
    'column' => [
        'values' => 'Values',
    ],
    'value' => [
        'label' => 'Label (:locale)',
        'code_help' => 'Stable identifier, unique within this option (e.g. "silver", "size-7").',
        'swatch_label' => 'Swatch image',
        'swatch_help' => 'Optional. Shown in the storefront variant picker.',
    ],
];
