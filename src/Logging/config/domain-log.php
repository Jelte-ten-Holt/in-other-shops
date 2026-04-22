<?php

declare(strict_types=1);

use InOtherShops\Logging\Handlers\FileLogHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Channel → Handler Mapping
    |--------------------------------------------------------------------------
    |
    | Each key is a logical channel name used in LogEntry objects. The value
    | is an array of handlers that will receive entries for that channel.
    | Each handler entry specifies the class and constructor arguments.
    |
    | Optional 'levels' key filters which log levels a handler receives.
    | Omit it to receive all levels. Values: debug, info, notice, warning,
    | error, critical.
    |
    | Example — file gets everything, database only errors:
    |   ['handler' => FileLogHandler::class, 'with' => ['channel' => 'commerce']],
    |   ['handler' => DatabaseLogHandler::class, 'with' => [], 'levels' => ['error', 'critical']],
    |
    */

    'channels' => [
        'flowchain' => [
            ['handler' => FileLogHandler::class, 'with' => ['channel' => 'flowchain']],
        ],
        'commerce' => [
            ['handler' => FileLogHandler::class, 'with' => ['channel' => 'commerce']],
        ],
        'inventory' => [
            ['handler' => FileLogHandler::class, 'with' => ['channel' => 'inventory']],
        ],
        'payment' => [
            ['handler' => FileLogHandler::class, 'with' => ['channel' => 'payment']],
        ],
        'agent' => [
            ['handler' => FileLogHandler::class, 'with' => ['channel' => 'agent']],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Handlers
    |--------------------------------------------------------------------------
    |
    | Entries whose channel has no explicit mapping above will be routed here.
    |
    */

    'default' => [
        ['handler' => FileLogHandler::class, 'with' => ['channel' => 'daily']],
    ],

];
