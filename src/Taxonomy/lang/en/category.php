<?php

declare(strict_types=1);

/**
 * Category admin strings — `en` source of truth. Domain-specific strings only;
 * recurring field labels (Name, Slug, Position, Active) come from
 * `shops-common::fields.*`.
 */
return [
    'section' => [
        'details' => 'Category Details',
        'cover_image' => 'Cover Image',
        'assigned_items' => 'Assigned items',
        'assigned_items_description' => 'Products, bundles and content currently in this category, grouped by type.',
    ],
    'fields' => [
        'parent' => 'Parent Category',
        'parent_placeholder' => 'None (root category)',
        'tags' => 'Tags',
        'tags_help' => 'Flags for this category — e.g. a "featured" tag to surface it on the storefront home page.',
        'cover_image' => 'Cover image',
        'cover_image_help' => 'Shown on category teasers and listings.',
    ],
    'columns' => [
        'parent' => 'Parent',
    ],
    'assigned' => [
        'empty' => 'No products, bundles or content are assigned to this category or its sub-categories yet.',
    ],
    'delete' => [
        'confirm' => 'Are you sure you want to delete this category? This action cannot be undone.',
    ],
];
