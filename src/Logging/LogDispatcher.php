<?php

declare(strict_types=1);

namespace InOtherShops\Logging;

use InOtherShops\Logging\Contracts\LogHandler;
use InOtherShops\Logging\DTOs\LogEntry;

final class LogDispatcher
{
    /**
     * @param  array<string, list<LogHandler>>  $handlers
     * @param  list<LogHandler>  $default
     */
    public function __construct(
        private readonly array $handlers,
        private readonly array $default,
        private readonly LogContext $context,
    ) {}

    public function log(LogEntry $entry): void
    {
        $entry = $this->enrichEntry($entry);

        $targets = $this->handlers[$entry->channel] ?? $this->default;

        foreach ($targets as $handler) {
            $handler->handle($entry);
        }
    }

    private function enrichEntry(LogEntry $entry): LogEntry
    {
        $ambient = $this->context->all();

        if (empty($ambient)) {
            return $entry;
        }

        return new LogEntry(
            level: $entry->level,
            channel: $entry->channel,
            message: $entry->message,
            context: array_merge($ambient, $entry->context),
        );
    }
}
