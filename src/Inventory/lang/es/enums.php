<?php

declare(strict_types=1);

/**
 * Inventory enum admin labels — Spanish (draft — needs-es-review). Keys mirror
 * en/enums.php exactly (backing values).
 */
return [
    'ReservationStatus' => [
        'pending' => 'Pendiente',
        'confirmed' => 'Confirmada',
        'released' => 'Liberada',
    ],
    'StockMovementReason' => [
        'received' => 'Recibido',
        'restock' => 'Reabastecido',
        'sold' => 'Vendido',
        'reserved' => 'Reservado',
        'released' => 'Liberado',
        'adjusted' => 'Ajustado',
    ],
];
