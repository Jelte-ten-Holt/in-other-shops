<?php

declare(strict_types=1);

/**
 * Supplier admin strings — Spanish (draft — needs-es-review). Keys mirror
 * en/supplier.php exactly.
 */
return [
    'model' => 'proveedor',
    'model_plural' => 'proveedores',
    'nav' => 'Proveedores',

    'section' => [
        'supplier' => 'Proveedor',
        'invoicing' => 'IVA y dirección',
    ],
    'fields' => [
        'contact_email' => 'Correo de contacto',
        'default_currency' => 'Moneda predeterminada',
        'payment_terms' => 'Términos de pago',
        'payment_terms_placeholder' => 'p. ej. Net 30',
        'vat_id' => 'NIF-IVA',
        'country_code' => 'País',
        'country_code_help' => 'Código de dos letras, p. ej. DE, ES, NL.',
        'address_line_1' => 'Dirección',
        'address_line_2' => 'Dirección (línea 2)',
        'postal_code' => 'Código postal',
        'city' => 'Ciudad',
    ],
    'columns' => [
        'pos' => 'OCs',
    ],
];
