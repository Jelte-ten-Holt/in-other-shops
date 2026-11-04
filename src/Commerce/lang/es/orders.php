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
        'stock' => 'Stock',
    ],
    'stock_state' => [
        'stale' => 'Con stock reservado desde hace :days días',
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
        'mark_paid_modal' => 'Registra un pago del total completo, recibido fuera del pago con tarjeta (una transferencia bancaria), en el día que indiques. El pedido se trata entonces como si se hubiera pagado con tarjeta: se confirma y el cliente recibe la confirmación del pedido. Después ya no se pueden cambiar sus precios, líneas ni direcciones. El stock no se ajusta: este pedido no reserva existencias, así que mantén tú la disponibilidad de las piezas.',
        'mark_paid_modal_holding_stock' => 'Registra un pago del total completo, recibido fuera del pago con tarjeta (una transferencia bancaria), en el día que indiques. El pedido se trata entonces como si se hubiera pagado con tarjeta: se confirma y el cliente recibe la confirmación del pedido. Después ya no se pueden cambiar sus precios, líneas ni direcciones. El stock que tiene reservado se confirma con el pago.',
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
    'stock' => [
        'mode' => 'Stock',
        'modes' => [
            'stock' => 'Tomar del stock',
            'hold' => 'Tomar de un apartado',
            'none' => 'No tomar stock',
            'none_help' => 'Para un pedido cuyo stock ya se corrigió a mano.',
        ],
        'hold' => 'Apartado',
        'split' => [
            'label' => 'Stock',
            'from_hold' => ':count de :hold',
            'from_stock' => ':count del stock',
            'backordered' => ':count bajo pedido',
            'component' => ':item: :split',
        ],
        'errors' => [
            'short' => 'Solo hay :available en stock.',
            'short_held' => ':available en stock; :held — elige «Tomar de un apartado».',
            'held_in' => ':count apartado(s) en :hold',
            'short_after_hold' => ':from_hold de :hold; solo hay :available en stock para el resto.',
        ],
        'lines_locked' => 'Este pedido tiene stock reservado para sus líneas, así que están bloqueadas. Para cambiarlas, borra el pedido y créalo de nuevo.',
        'held_summary' => 'Stock reservado',
        'held_line' => ':count × :item (:source)',
        'from_stock' => 'del stock',
        'from_hold' => 'de :hold',
        'notifications' => [
            'refused' => 'Pedido no creado',
            'try_again' => 'Otra persona estaba cambiando el mismo stock. No se ha creado nada: inténtalo de nuevo.',
            'hold_missing' => 'El apartado ya no existe.',
        ],
    ],
];
