<?php

declare(strict_types=1);

namespace InOtherShops\Logging;

use Illuminate\Support\Facades\Log;
use InOtherShops\Logging\Contracts\LogHandler;
use InOtherShops\Logging\DTOs\LogEntry;
use Throwable;

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
            try {
                $handler->handle($entry);
            } catch (Throwable $e) {
                $this->degradeHandlerFailure($entry, $handler, $e);
            }
        }
    }

    /**
     * The audit echo must never fail the business action it observes (G10).
     * Domain subscribers are synchronous and dispatch *after* the business
     * transaction has committed, so a throwing handler — most plausibly
     * {@see Handlers\DatabaseLogHandler} hitting a DB error after stock or a
     * payment already moved — would turn a successful action into a 500 caused
     * by its own audit trail. Each handler is therefore isolated: on failure the
     * entry is degraded to the application log (file-backed by default) so the
     * record survives and the failure is visible, and the action proceeds. The
     * application log itself failing is swallowed to `error_log` as a last resort
     * — this path is never allowed to throw.
     */
    private function degradeHandlerFailure(LogEntry $entry, LogHandler $handler, Throwable $e): void
    {
        try {
            Log::error('Audit log handler failed — entry degraded to the application log', [
                'handler' => $handler::class,
                'audit_channel' => $entry->channel,
                'audit_level' => $entry->level->value,
                'audit_message' => $entry->message,
                'audit_context' => $entry->context,
                'exception' => $e->getMessage(),
            ]);
        } catch (Throwable) {
            error_log(sprintf(
                '[in-other-shops] audit handler %s failed on channel "%s" (%s): %s',
                $handler::class,
                $entry->channel,
                $entry->message,
                $e->getMessage(),
            ));
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
