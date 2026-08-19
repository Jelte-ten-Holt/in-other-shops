<?php

declare(strict_types=1);

/**
 * Media schema admin strings — Spanish (draft — needs-es-review). Keys mirror
 * en/media.php exactly.
 */
return [
    'fields' => [
        'path' => 'Ruta',
        'url' => 'URL',
        'alt' => 'Texto alternativo',
        'description' => 'Descripción',
        'description_help' => 'Se muestra debajo de la imagen en el sitio. Déjalo vacío para no mostrar pie de foto. El texto escrito junto a la imagen en el cuerpo del contenido tiene prioridad sobre este.',
        'is_cover' => 'Usar como imagen de portada',
        'is_cover_help' => 'La imagen de portada se usa en los listados y en las vistas previas para redes sociales. Solo se conserva una fila como portada en todas las colecciones de medios.',
    ],
    'embed' => [
        'prompt' => 'Pega arriba una URL de YouTube o Vimeo.',
    ],
    'type_options' => [
        'upload' => 'Subida',
        'external' => 'URL externa',
        'embed' => 'Incrustado',
    ],
];
