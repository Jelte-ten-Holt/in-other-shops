<?php

declare(strict_types=1);

/**
 * Supplier admin strings — `en` source of truth. Domain-specific strings only;
 * recurring field labels (Name, Notes, Created at) come from
 * `shops-common::fields.*`.
 */
return [
    'model' => 'supplier',
    'model_plural' => 'suppliers',
    'nav' => 'Suppliers',

    'section' => [
        'supplier' => 'Supplier',
        'invoicing' => 'VAT and address',
    ],
    'fields' => [
        'contact_email' => 'Contact email',
        'default_currency' => 'Default currency',
        'payment_terms' => 'Payment terms',
        'payment_terms_placeholder' => 'e.g. Net 30',
        'vat_id' => 'VAT ID',
        'country_code' => 'Country',
        'country_code_help' => 'Two-letter code, e.g. DE, ES, NL.',
        'address_line_1' => 'Address',
        'address_line_2' => 'Address line 2',
        'postal_code' => 'Postal code',
        'city' => 'City',
    ],
    'columns' => [
        'pos' => 'POs',
    ],
];
