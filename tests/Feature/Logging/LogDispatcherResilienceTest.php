<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Logging;

use Illuminate\Support\Facades\Log;
use InOtherShops\Logging\Contracts\LogHandler;
use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogContext;
use InOtherShops\Logging\LogDispatcher;
use InOtherShops\Tests\Stubs\RecordingLogHandler;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Audit subscribers are synchronous and run *after* the business transaction
 * commits, so a throwing handler would 500 an already-successful action via its
 * own audit echo (G10). The dispatcher must isolate each handler: never
 * propagate, never let one handler starve the next, always leave a trace.
 */
final class LogDispatcherResilienceTest extends TestCase
{
    #[Test]
    public function a_throwing_handler_does_not_propagate_and_does_not_starve_later_handlers(): void
    {
        Log::spy();

        $throwing = new class implements LogHandler
        {
            public function handle(LogEntry $entry): void
            {
                throw new RuntimeException('database is down');
            }
        };
        $recording = new RecordingLogHandler;

        $dispatcher = new LogDispatcher(
            handlers: ['commerce' => [$throwing, $recording]],
            default: [],
            context: new LogContext,
        );

        $entry = new LogEntry(LogLevel::Info, 'commerce', 'order confirmed', ['order_id' => 42]);

        // The whole point: this call must not bubble the handler's exception.
        $dispatcher->log($entry);

        // The handler after the failing one still ran.
        $this->assertCount(1, $recording->entries());
        $this->assertSame('order confirmed', $recording->lastEntry()->message);

        // The dropped entry was degraded to the application log, not lost silently.
        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function a_healthy_channel_dispatches_to_every_handler_unaffected(): void
    {
        $a = new RecordingLogHandler;
        $b = new RecordingLogHandler;

        $dispatcher = new LogDispatcher(
            handlers: ['payment' => [$a, $b]],
            default: [],
            context: new LogContext,
        );

        $dispatcher->log(new LogEntry(LogLevel::Notice, 'payment', 'captured', []));

        $this->assertCount(1, $a->entries());
        $this->assertCount(1, $b->entries());
    }
}
