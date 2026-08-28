<?php

declare(strict_types=1);

/**
 * Shipment admin strings — Spanish (draft — needs-es-review). Keys mirror
 * en/shipment.php exactly.
 */
return [
    'title' => 'Envíos',

    'columns' => [
        'method' => 'Método',
        'carrier' => 'Transportista',
        'tracking' => 'Seguimiento',
        'shipped' => 'Enviado',
        'delivered' => 'Entregado',
        'created' => 'Creado',
    ],
    'form' => [
        'carrier' => 'Transportista',
        'tracking_number' => 'Número de seguimiento',
        'tracking_number_help' => 'Déjalo en blanco para envío ordinario sin seguimiento — el envío se despacha y notifica igualmente.',
        'tracking_url' => 'URL de seguimiento',
        'tracking_url_help' => 'Déjalo en blanco para derivarla de la plantilla del transportista (config/shipping.carriers).',
        'reason' => 'Motivo',
    ],
    'actions' => [
        'mark_ready' => 'Marcar como listo',
        'dispatch' => 'Despachar',
        'mark_delivered' => 'Marcar como entregado',
        'mark_returned_to_sender' => 'Marcar como devuelto al remitente',
        'mark_lost' => 'Marcar como perdido',
    ],
    'notifications' => [
        'marked_ready' => 'Envío marcado como listo',
        'cannot_dispatch' => 'No se puede despachar el envío',
        'cannot_dispatch_body' => 'El importe asociado no está pagado en su totalidad.',
        'dispatched' => 'Envío despachado',
        'marked_delivered' => 'Envío marcado como entregado',
        'marked_returned_to_sender' => 'Envío marcado como devuelto al remitente',
        'marked_lost' => 'Envío marcado como perdido',
    ],
];
