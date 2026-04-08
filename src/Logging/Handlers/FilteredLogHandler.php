<?php

declare(strict_types=1);

namespace InOtherShops\Logging\Handlers;

use InOtherShops\Logging\Contracts\LogHandler;
use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;

final class FilteredLogHandler implements LogHandler
{
    /**
     * @param  list<LogLevel>  $levels
     */
    public function __construct(
        private readonly LogHandler $inner,
        private readonly array $levels,
    ) {}

    public function handle(LogEntry $entry): void
    {
        if (in_array($entry->level, $this->levels, true)) {
            $this->inner->handle($entry);
        }
    }
}
