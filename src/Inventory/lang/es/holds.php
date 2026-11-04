<?php

declare(strict_types=1);

/**
 * Textos de los apartados de stock. Borrador para revisar (Bianka).
 */
return [
    'model' => 'apartado',
    'model_plural' => 'apartados',
    'nav' => 'Apartados',

    'fields' => [
        'name' => 'Nombre',
        'name_help' => 'Para qué se aparta el stock, p. ej. «Mercado 15 nov» o «Para Ana».',
        'held_for' => 'Para el día',
        'held_for_help' => 'Opcional: el día al que corresponde, como un mercado. El apartado se señala si sigue abierto mucho después de ese día.',
        'products' => 'Productos',
        'products_help' => 'Al guardar, cada producto queda en la cantidad que indiques. Pon 0, o quítalo, para volver a ponerlo a la venta.',
        'product' => 'Producto',
    ],
    'columns' => [
        'units' => 'Unidades',
        'age' => 'Antigüedad',
    ],
    'state' => [
        'stale' => 'Abierto después de su día',
        'released' => 'Liberado',
    ],
    'filters' => [
        'released' => 'Liberado',
    ],
    'actions' => [
        'add_product' => 'Añadir producto',
        'release' => 'Liberar',
        'release_modal' => 'Todo lo que aún tiene este apartado vuelve a estar a la venta y el apartado se cierra. No se puede volver a abrir.',
    ],
    'notifications' => [
        'released' => ':name liberado',
        'refused' => 'Apartado no guardado',
        'try_again' => 'Otra persona estaba cambiando el mismo stock. No se ha guardado nada: inténtalo de nuevo.',
    ],
];
