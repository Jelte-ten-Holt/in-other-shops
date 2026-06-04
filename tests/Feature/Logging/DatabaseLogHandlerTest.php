<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Logging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\Handlers\DatabaseLogHandler;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The audit row must never silently lose its context (G10): plain json_encode
 * returns the literal `false` — not throwing — on unencodable input, and free-
 * text audit fields (an error message, a shipment reason, a voucher code) can
 * carry exactly that. The handler must store recoverable JSON in every case.
 */
final class DatabaseLogHandlerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_substitutes_invalid_utf8_instead_of_storing_false(): void
    {
        (new DatabaseLogHandler)->handle(new LogEntry(
            level: LogLevel::Error,
            channel: 'commerce',
            message: 'order failed',
            context: ['reason' => "bad \xB1\x31 byte", 'order_id' => 7],
        ));

        $stored = DB::table('domain_logs')->where('channel', 'commerce')->value('context');

        // The bug stored the four characters f-a-l-s-e; the fix stores real JSON.
        $this->assertNotSame('false', $stored);
        $decoded = json_decode((string) $stored, true);
        $this->assertIsArray($decoded);
        $this->assertSame(7, $decoded['order_id']);
        $this->assertArrayHasKey('reason', $decoded);
    }

    #[Test]
    public function it_stores_a_recoverable_sentinel_when_the_context_cannot_be_encoded(): void
    {
        // INF/NAN are rejected even with UTF-8 substitution → the encode throws,
        // and we store a sentinel naming the failure and the lost keys rather
        // than dropping the whole context.
        (new DatabaseLogHandler)->handle(new LogEntry(
            level: LogLevel::Error,
            channel: 'payment',
            message: 'weird',
            context: ['amount' => INF, 'gateway' => 'stripe'],
        ));

        $stored = DB::table('domain_logs')->where('channel', 'payment')->value('context');
        $decoded = json_decode((string) $stored, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('_context_encode_error', $decoded);
        $this->assertContains('gateway', $decoded['_keys']);
    }

    #[Test]
    public function it_round_trips_ordinary_context_unchanged(): void
    {
        (new DatabaseLogHandler)->handle(new LogEntry(
            level: LogLevel::Info,
            channel: 'inventory',
            message: 'adjusted',
            context: ['sku' => 'ABC-1', 'delta' => -3, 'nested' => ['a' => 1]],
        ));

        $stored = DB::table('domain_logs')->where('channel', 'inventory')->value('context');

        $this->assertSame(
            ['sku' => 'ABC-1', 'delta' => -3, 'nested' => ['a' => 1]],
            json_decode((string) $stored, true),
        );
    }
}
