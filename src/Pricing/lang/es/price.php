<?php

declare(strict_types=1);

/**
 * Price admin strings — Spanish (draft — needs-es-review). Keys mirror
 * en/price.php exactly.
 */
return [
    'amount' => 'Monto',
    'minimum_quantity' => 'Cantidad mínima',
    'price_list' => 'Lista de precios',
    'compare_at_amount' => 'Precio tachado',
    'compare_at_tooltip' => "Usa solo un precio al que este artículo se haya vendido realmente hace poco. Inventar un precio 'original' más alto para simular un descuento es ilegal según las normas de precios de la UE (Directiva Ómnibus).",
    'compare_at_create_blocked' => 'No puedes poner un precio tachado al crear un precio por primera vez: no hay un precio anterior al que se haya vendido el artículo. Guarda el precio y luego agrega el tachado.',
    'compare_at_too_high' => 'El precio tachado no puede ser mayor que el precio actual de este artículo. Usa un precio al que se haya vendido realmente antes.',
    'compare_at_until' => 'Fin del precio tachado',
    'compare_at_until_help' => 'Cuando esto pase, el precio tachado se convierte en el precio real y el tachado se elimina. Las horas están en la zona horaria configurada de la tienda (:timezone).',
    'strikethrough' => 'Tachado',
];
