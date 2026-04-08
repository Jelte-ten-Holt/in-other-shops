<?php

declare(strict_types=1);

namespace InOtherShops\Logging\DTOs;

use InOtherShops\Logging\Enums\LogLevel;

final readonly class LogEntry
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public LogLevel $level,
        public string $channel,
        public string $message,
        public array $context = [],
    ) {}
}
