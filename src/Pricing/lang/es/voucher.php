<?php

declare(strict_types=1);

/**
 * Voucher admin strings — Spanish (draft — needs-es-review). Keys mirror
 * en/voucher.php exactly.
 */
return [
    'section' => [
        'details' => 'Detalles del cupón',
        'restrictions' => 'Restricciones',
    ],
    'code_help' => 'Se agregará un sufijo aleatorio de 4 letras (p. ej. SUMMER → SUMMER-KXPQ).',
    'amount' => 'Monto',
    'amount_help_percentage' => 'Porcentaje sencillo (p. ej. 10 = 10 %). Se almacena internamente como puntos básicos.',
    'amount_help_fixed' => 'Monto en la subunidad de moneda más pequeña (centavos para EUR).',
    'minimum_order_amount' => 'Monto mínimo del pedido',
    'minimum_order_amount_help' => 'Subtotal mínimo del pedido en centavos (0 = sin mínimo)',
    'max_uses' => 'Usos máximos',
    'max_uses_placeholder' => 'Ilimitado',
    'valid_from' => 'Válido desde',
    'valid_from_placeholder' => 'Sin fecha de inicio',
    'valid_until' => 'Válido hasta',
    'valid_until_placeholder' => 'Sin vencimiento',
    'uses' => 'Usos',
    'type_options' => [
        'fixed' => 'Monto fijo',
        'percentage' => 'Porcentaje',
    ],
];
