<?php

declare(strict_types=1);

/**
 * Order admin strings — Spanish (draft — needs-es-review). Keys mirror
 * en/orders.php exactly.
 */
return [
    'tabs' => [
        'details' => 'Detalles',
        'order_lines' => 'Líneas del pedido',
        'addresses' => 'Direcciones',
    ],
    'fields' => [
        'item' => 'Artículo',
        'unit_price' => 'Precio unitario',
        'line_total' => 'Total de línea',
        'customer' => 'Cliente',
        'order_number' => 'Número de pedido',
        'subtotal' => 'Subtotal',
        'tax' => 'Impuesto',
        'discount' => 'Descuento',
        'total' => 'Total',
        'shipping_cost' => 'Costo de envío',
        'new_status' => 'Nuevo estado',
        'reason' => 'Motivo',
        'refund_amount' => 'Monto a reembolsar (unidades menores)',
        'refund_amount_help' => 'Déjalo en blanco para el saldo restante completo.',
        'restock' => 'Reponer estos artículos (opcional)',
        'restock_help' => 'Devuelve las reservas elegidas al stock disponible.',
    ],
    'columns' => [
        'refund' => 'Reembolso',
        'shipping' => 'Envío',
    ],
    'placeholders' => [
        'guest' => 'Invitado',
        'guest_no_customer' => 'Invitado (sin cliente)',
    ],
    'refund_state' => [
        'refunded' => 'Reembolsado',
        'partial' => 'Parcial',
    ],
    'actions' => [
        'update_status' => 'Actualizar estado',
        'partial_refund' => 'Reembolso parcial',
        'refund_and_cancel' => 'Reembolsar y cancelar pedido',
        'refund_and_cancel_modal' => 'Reembolsa el saldo restante completo, cancela el pedido y libera todo el stock reservado.',
    ],
    'notifications' => [
        'fully_refunded_title' => 'Este pedido está totalmente reembolsado',
        'fully_refunded_body' => 'Sigue Confirmado — no lo completes ni lo envíes sin verificar.',
        'partially_refunded_title' => 'Este pedido está parcialmente reembolsado',
        'refund_refused' => 'Reembolso rechazado',
        'refund_failed' => 'Error en el reembolso',
        'refund_issued' => 'Reembolso emitido',
    ],
    'address' => [
        'first_name' => 'Nombre',
        'last_name' => 'Apellido',
        'line_1' => 'Dirección línea 1',
        'line_2' => 'Dirección línea 2',
        'city' => 'Ciudad',
        'state' => 'Estado',
        'postal_code' => 'Código postal',
        'phone' => 'Teléfono',
        'address' => 'Dirección',
    ],
];
