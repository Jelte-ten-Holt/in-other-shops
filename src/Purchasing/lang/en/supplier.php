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
    ],
    'fields' => [
        'contact_email' => 'Contact email',
        'default_currency' => 'Default currency',
        'payment_terms' => 'Payment terms',
        'payment_terms_placeholder' => 'e.g. Net 30',
    ],
    'columns' => [
        'pos' => 'POs',
    ],
];
