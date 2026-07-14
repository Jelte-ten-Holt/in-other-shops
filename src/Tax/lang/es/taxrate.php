<?php

declare(strict_types=1);

/**
 * Tax rate admin strings — Spanish (draft — needs-es-review). Keys mirror
 * en/taxrate.php exactly.
 */
return [
    'model' => 'tasa de impuesto',
    'model_plural' => 'tasas de impuesto',
    'nav' => 'Tasas de impuesto',

    'section' => [
        'tax_rate' => 'Tasa de impuesto',
    ],
    'fields' => [
        'name_placeholder' => 'p. ej. IVA Países Bajos 21%',
        'country_code' => 'Código de país (ISO-3166-1 alfa-2)',
        'country_code_help' => 'Código de dos letras en mayúsculas, p. ej. NL, DE, FR.',
        'tax_category' => 'Categoría de impuesto',
        'tax_category_placeholder' => 'General — se aplica a cualquier categoría',
        'tax_category_help' => 'Déjalo en blanco para la tasa general del país. Elige una categoría para anular esa tasa en tipos de producto específicos.',
        'rate_bps' => 'Tasa (puntos básicos)',
        'rate_bps_help' => '2100 = 21%. 900 = 9%. 0 = exento.',
        'is_default' => 'Reserva predeterminada',
        'is_default_help' => 'Se usa cuando no se encuentra ninguna coincidencia de país. Solo una tasa debe marcarse como predeterminada.',
    ],
    'columns' => [
        'category' => 'Categoría',
        'category_placeholder' => 'General',
        'rate' => 'Tasa',
        'is_default' => 'Predeterminada',
    ],
];
