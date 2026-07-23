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
    ],
    'fields' => [
        'supplier' => 'Proveedor',
        'expected_delivery' => 'Entrega esperada',
        'shipping_cost' => 'Costo de envío',
        'customs_cost' => 'Costo de aduana',
    ],
    'columns' => [
        'total' => 'Total',
        'expected' => 'Esperada',
    ],
    'actions' => [
        'place' => 'Marcar como pedido',
        'receive' => 'Recibir',
        'cancel' => 'Cancelar',
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
