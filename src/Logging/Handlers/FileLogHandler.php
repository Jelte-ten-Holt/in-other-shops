<?php

declare(strict_types=1);

namespace InOtherShops\Logging\Handlers;

use InOtherShops\Logging\Contracts\LogHandler;
use InOtherShops\Logging\DTOs\LogEntry;
use Illuminate\Support\Facades\Log;

final class FileLogHandler implements LogHandler
{
    public function __construct(
        private readonly string $channel,
    ) {}

    public function handle(LogEntry $entry): void
    {
        Log::channel($this->channel)->log(
            $entry->level->value,
            $entry->message,
            $entry->context,
        );
    }
}
