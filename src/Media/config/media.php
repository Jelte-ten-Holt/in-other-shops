<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk used to store uploaded media files.
    |
    */
    'disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Storage Directory
    |--------------------------------------------------------------------------
    |
    | The base directory within the disk where media files are stored.
    |
    */
    'directory' => env('MEDIA_DIRECTORY', 'media'),

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | Define the available media collections. Keys are stored in the database;
    | labels are shown in the UI and can be passed through __() for translation.
    |
    */
    'collections' => [
        'images' => [
            'label' => 'Images',
        ],
        'documents' => [
            'label' => 'Documents',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Media domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'media' => InOtherShops\Media\Models\Media::class,
        'mediable' => InOtherShops\Media\Models\Mediable::class,
    ],
];
