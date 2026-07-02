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
        'purchasing' => [
            ['handler' => FileLogHandler::class, 'with' => ['channel' => 'purchasing']],
        ],
        'shipping' => [
            ['handler' => FileLogHandler::class, 'with' => ['channel' => 'shipping']],
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

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | The `domain_logs` table (written by DatabaseLogHandler) is an append-only
    | observability echo — not a system of record. The
    | `logging:prune-domain-logs` command deletes rows older than
    | `retention_days`; when `schedule.enabled` is true it runs daily on the
    | Laravel scheduler so the table stays bounded without manual intervention.
    |
    */

    'retention_days' => env('DOMAIN_LOG_RETENTION_DAYS', 90),

    'schedule' => [
        'enabled' => true,
    ],

];
