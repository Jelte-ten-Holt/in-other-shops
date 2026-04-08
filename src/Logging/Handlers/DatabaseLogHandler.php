<?php

declare(strict_types=1);

namespace InOtherShops\Logging\Handlers;

use InOtherShops\Logging\Contracts\LogHandler;
use InOtherShops\Logging\DTOs\LogEntry;
use Illuminate\Support\Facades\DB;

final class DatabaseLogHandler implements LogHandler
{
    public function handle(LogEntry $entry): void
    {
        DB::table('domain_logs')->insert([
            'level' => $entry->level->value,
            'channel' => $entry->channel,
            'message' => $entry->message,
            'context' => json_encode($entry->context),
            'created_at' => now(),
        ]);
    }
}
