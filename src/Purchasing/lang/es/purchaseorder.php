<?php

declare(strict_types=1);

/**
 * Purchase order admin strings — Spanish (draft — needs-es-review). Keys mirror
 * en/purchaseorder.php exactly.
 */
return [
    'model' => 'orden de compra',
    'model_plural' => 'órdenes de compra',
    'nav' => 'Órdenes de compra',

    'section' => [
        'details' => 'Detalles',
        'lines' => 'Líneas',
        'supplier_invoice' => 'Factura del proveedor',
        'payment' => 'Pago',
    ],
    'fields' => [
        'supplier' => 'Proveedor',
        'expected_delivery' => 'Entrega esperada',
        'shipping_cost' => 'Costo de envío',
        'customs_cost' => 'Costo de aduana',
        'supplier_invoice_number' => 'Número de factura',
        'supplier_invoice_date' => 'Fecha de factura',
        'supplier_invoice_total' => 'Total de la factura (bruto)',
        'supplier_invoice_total_help' => 'Tal como figura en la factura. Debe ser igual a la base más el IVA de los tipos de abajo.',
        'supplier_invoice_tax_summary' => 'IVA por tipo',
        'vat_rate' => 'Tipo de IVA',
        'net' => 'Base',
        'vat' => 'IVA',
        'paid_on' => 'Pagado el',
        'amount_paid' => 'Importe pagado',
    ],
    'columns' => [
        'total' => 'Total',
        'expected' => 'Esperada',
    ],
    'actions' => [
        'place' => 'Marcar como pedido',
        'receive' => 'Recibir',
        'cancel' => 'Cancelar',
        'add_rate' => 'Añadir un tipo de IVA',
    ],
    'notifications' => [
        'placed' => 'Orden de compra realizada',
        'nothing_to_receive' => 'No hay nada que recibir',
        'items_received' => 'Artículos recibidos',
        'receive_failed' => 'No se pudieron recibir los artículos',
        'cancelled' => 'Orden de compra cancelada',
        'cancel_failed' => 'No se pudo cancelar',
    ],
];
