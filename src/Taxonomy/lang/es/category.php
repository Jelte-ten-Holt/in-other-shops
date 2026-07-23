<?php

declare(strict_types=1);

/**
 * Category admin strings — Spanish (draft — needs-es-review). Keys mirror
 * en/category.php exactly.
 */
return [
    'model' => 'categoría',
    'model_plural' => 'categorías',
    'nav' => 'Categorías',
    'relation_title' => 'Categorías',

    'section' => [
        'details' => 'Detalles de la categoría',
        'cover_image' => 'Imagen de portada',
        'assigned_items' => 'Elementos asignados',
        'assigned_items_description' => 'Productos, paquetes y contenido que están actualmente en esta categoría, agrupados por tipo.',
    ],
    'fields' => [
        'parent' => 'Categoría padre',
        'parent_placeholder' => 'Ninguna (categoría raíz)',
        'tags' => 'Etiquetas',
        'tags_help' => 'Marcadores para esta categoría, p. ej. una etiqueta "destacada" para mostrarla en la página de inicio de la tienda.',
        'cover_image' => 'Imagen de portada',
        'cover_image_help' => 'Se muestra en los teasers y listados de categorías.',
    ],
    'columns' => [
        'parent' => 'Padre',
    ],
    'assigned' => [
        'empty' => 'Todavía no hay productos, paquetes ni contenido asignados a esta categoría ni a sus subcategorías.',
    ],
    'delete' => [
        'confirm' => '¿Seguro que quieres eliminar esta categoría? Esta acción no se puede deshacer.',
    ],
];
