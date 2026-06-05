<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Logging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Logging\DTOs\LogActor;
use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogActorType;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\Handlers\DatabaseLogHandler;
use InOtherShops\Logging\LogContext;
use InOtherShops\Logging\LogDispatcher;
use InOtherShops\Tests\Stubs\RecordingLogHandler;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every audit row must record *who* (F21). The actor is set once at a boundary
 * and inherited ambiently; a few operations override it explicitly. The
 * dispatcher resolves a single precedence — explicit > ambient > unknown() — so
 * no row is ever left anonymous.
 */
final class LogActorAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function dispatcher(LogContext $context, RecordingLogHandler $recording): LogDispatcher
    {
        return new LogDispatcher(
            handlers: ['commerce' => [$recording]],
            default: [],
            context: $context,
        );
    }

    #[Test]
    public function an_explicit_entry_actor_overrides_the_ambient_boundary_actor(): void
    {
        $context = new LogContext;
        $context->setActor(LogActor::user('7', 'Jelte'));
        $recording = new RecordingLogHandler;

        $this->dispatcher($context, $recording)->log(new LogEntry(
            level: LogLevel::Notice,
            channel: 'commerce',
            message: 'refund issued',
            actor: LogActor::gateway('stripe'),
        ));

        $actor = $recording->lastEntry()->actor;
        $this->assertSame(LogActorType::Gateway, $actor->type);
        $this->assertSame('stripe', $actor->label);
    }

    #[Test]
    public function the_ambient_boundary_actor_is_used_when_the_entry_has_none(): void
    {
        $context = new LogContext;
        $context->setActor(LogActor::user('7', 'Jelte'));
        $recording = new RecordingLogHandler;

        $this->dispatcher($context, $recording)->log(new LogEntry(
            level: LogLevel::Info,
            channel: 'commerce',
            message: 'order confirmed',
        ));

        $actor = $recording->lastEntry()->actor;
        $this->assertSame(LogActorType::User, $actor->type);
        $this->assertSame('7', $actor->id);
        $this->assertSame('Jelte', $actor->label);
    }

    #[Test]
    public function an_entry_with_no_explicit_or_ambient_actor_resolves_to_unknown(): void
    {
        $recording = new RecordingLogHandler;

        $this->dispatcher(new LogContext, $recording)->log(new LogEntry(
            level: LogLevel::Info,
            channel: 'commerce',
            message: 'order confirmed',
        ));

        $actor = $recording->lastEntry()->actor;
        $this->assertSame(LogActorType::System, $actor->type);
        $this->assertNull($actor->id);
        $this->assertSame('unknown', $actor->label);
    }

    #[Test]
    public function forgetting_the_ambient_actor_falls_back_to_unknown(): void
    {
        $context = new LogContext;
        $context->setActor(LogActor::user('7', 'Jelte'));
        $context->forgetActor();
        $recording = new RecordingLogHandler;

        $this->dispatcher($context, $recording)->log(new LogEntry(
            level: LogLevel::Info,
            channel: 'commerce',
            message: 'order confirmed',
        ));

        $this->assertSame('unknown', $recording->lastEntry()->actor->label);
    }

    #[Test]
    public function the_resolved_actor_is_written_to_dedicated_columns(): void
    {
        (new DatabaseLogHandler)->handle(new LogEntry(
            level: LogLevel::Notice,
            channel: 'commerce',
            message: 'refund issued',
            actor: LogActor::user('7', 'Jelte'),
        ));

        $row = DB::table('domain_logs')->where('channel', 'commerce')->first();

        $this->assertSame('user', $row->actor_type);
        $this->assertSame('7', $row->actor_id);
        $this->assertSame('Jelte', $row->actor_label);
    }

    #[Test]
    public function a_handler_given_an_actorless_entry_records_unknown_not_null(): void
    {
        (new DatabaseLogHandler)->handle(new LogEntry(
            level: LogLevel::Info,
            channel: 'inventory',
            message: 'adjusted',
        ));

        $row = DB::table('domain_logs')->where('channel', 'inventory')->first();

        $this->assertSame('system', $row->actor_type);
        $this->assertNull($row->actor_id);
        $this->assertSame('unknown', $row->actor_label);
    }

    #[Test]
    public function guest_is_a_user_type_actor_with_no_id(): void
    {
        $guest = LogActor::guest();

        $this->assertSame(LogActorType::User, $guest->type);
        $this->assertNull($guest->id);
        $this->assertSame('guest', $guest->label);
    }
}
