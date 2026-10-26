<?php

declare(strict_types=1);

/**
 * Order admin strings — Spanish (draft — needs-es-review). Keys mirror
 * en/orders.php exactly.
 */
return [
    'model' => 'pedido',
    'model_plural' => 'pedidos',
    'nav' => 'Pedidos',
    'relation_title' => 'Pedidos',

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
        'voucher_code' => 'Código de descuento',
        'paid_on' => 'Pagado el',
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
        'mark_paid' => 'Marcar como pagado',
        'mark_paid_modal' => 'Registra un pago del total completo, recibido fuera del pago con tarjeta (una transferencia bancaria), en el día que indiques. El pedido se trata entonces como si se hubiera pagado con tarjeta: se confirma y el cliente recibe la confirmación del pedido. Después ya no se pueden cambiar sus precios, líneas ni direcciones.',
    ],
    'notifications' => [
        'fully_refunded_title' => 'Este pedido está totalmente reembolsado',
        'fully_refunded_body' => 'Sigue Confirmado — no lo completes ni lo envíes sin verificar.',
        'partially_refunded_title' => 'Este pedido está parcialmente reembolsado',
        'refund_refused' => 'Reembolso rechazado',
        'refund_failed' => 'Error en el reembolso',
        'refund_issued' => 'Reembolso emitido',
        'marked_paid' => 'Pago registrado',
        'mark_paid_refused' => 'No se pudo registrar el pago',
        'frozen_title' => 'Este pedido está pagado',
        'frozen_body' => 'Sus precios, líneas y direcciones son el registro de la venta y ya no se pueden cambiar.',
        'status_updated' => 'Estado del pedido actualizado a :status',
        'status_refused' => 'No se ha cambiado el estado',
    ],
];
