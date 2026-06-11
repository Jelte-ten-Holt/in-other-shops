<?php

declare(strict_types=1);

namespace InOtherShops\Logging;

use Illuminate\Contracts\Events\Dispatcher;
use InOtherShops\Logging\DTOs\LogActor;
use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;

/**
 * Base for the per-domain audit log subscribers: owns the dispatcher
 * dependency and the LogEntry plumbing so handlers state only what varies
 * — level, message, context, and (rarely) an explicit actor. The channel
 * stays a per-subscriber constant, deliberately visible in each subscriber
 * (Pricing logs to 'commerce' on purpose — that nuance must not vanish
 * into base-class magic).
 *
 * Lives in Logging, not Support: the base is inseparable from Logging's
 * types, and every subscriber already depends on this domain.
 */
abstract class LogSubscriberBase
{
    /** Per-domain audit channel; children must redeclare. */
    protected const string CHANNEL = '';

    public function __construct(
        protected readonly LogDispatcher $dispatcher,
    ) {}

    /** @return array<class-string, string> */
    abstract public function subscribe(Dispatcher $events): array;

    /**
     * @param  array<string, mixed>  $context
     * @param  ?LogActor  $actor  Only for operations that know their actor
     *                            better than the request boundary does
     *                            (refunds); everything else inherits the
     *                            ambient LogContext actor.
     */
    protected function log(LogLevel $level, string $message, array $context = [], ?LogActor $actor = null): void
    {
        $this->dispatcher->log(new LogEntry(
            level: $level,
            channel: static::CHANNEL,
            message: $message,
            context: $context,
            actor: $actor,
        ));
    }
}
