<?php

declare(strict_types=1);

namespace InOtherShops\Logging\DTOs;

use InOtherShops\Logging\Enums\LogLevel;

final readonly class LogEntry
{
    /**
     * @param  array<string, mixed>  $context
     * @param  ?LogActor  $actor  An explicit actor for this entry, overriding the
     *                            ambient boundary actor. Null in the common case —
     *                            the dispatcher resolves ambient → unknown(). Only
     *                            operations that know their own actor (refunds)
     *                            set this.
     */
    public function __construct(
        public LogLevel $level,
        public string $channel,
        public string $message,
        public array $context = [],
        public ?LogActor $actor = null,
    ) {}
}
