<?php

declare(strict_types=1);

/**
 * Tax rate admin strings — `en` source of truth. Domain-specific strings only;
 * recurring field labels (Name, Country) come from `shops-common::fields.*`.
 */
return [
    'section' => [
        'tax_rate' => 'Tax Rate',
    ],
    'fields' => [
        'name_placeholder' => 'e.g. Netherlands VAT 21%',
        'country_code' => 'Country code (ISO-3166-1 alpha-2)',
        'country_code_help' => 'Two-letter uppercase code, e.g. NL, DE, FR.',
        'tax_category' => 'Tax category',
        'tax_category_placeholder' => 'General — applies to any category',
        'tax_category_help' => "Leave blank for the country's general rate. Pick a category to override that rate for specific product types.",
        'rate_bps' => 'Rate (basis points)',
        'rate_bps_help' => '2100 = 21%. 900 = 9%. 0 = zero-rated.',
        'is_default' => 'Default fallback',
        'is_default_help' => 'Used when no country match is found. Only one rate should be marked as default.',
    ],
    'columns' => [
        'category' => 'Category',
        'category_placeholder' => 'General',
        'rate' => 'Rate',
        'is_default' => 'Default',
    ],
];
