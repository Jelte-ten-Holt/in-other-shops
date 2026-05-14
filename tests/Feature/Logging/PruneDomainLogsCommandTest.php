<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Logging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `domain_logs` is an append-only observability echo — without retention it
 * grows unbounded. The prune command keeps it bounded. It is not a system of
 * record (the StockMovement ledger, orders, and payments tables hold the
 * durable history), so pruned rows are deleted outright — no archive.
 */
final class PruneDomainLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_deletes_rows_older_than_the_configured_retention(): void
    {
        config()->set('domain-log.retention_days', 30);

        $this->log('old', Carbon::now()->subDays(31));
        $this->log('fresh', Carbon::now()->subDays(29));

        $this->artisan('logging:prune-domain-logs')
            ->expectsOutputToContain('Deleted 1 domain log row(s) older than 30 day(s).')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('domain_logs')->where('message', 'old')->count());
        $this->assertSame(1, DB::table('domain_logs')->where('message', 'fresh')->count());
    }

    #[Test]
    public function it_accepts_a_days_override(): void
    {
        config()->set('domain-log.retention_days', 90);

        $this->log('forty-five-days-old', Carbon::now()->subDays(45));

        // Default (90) keeps the row.
        $this->artisan('logging:prune-domain-logs')->assertSuccessful();
        $this->assertSame(1, DB::table('domain_logs')->count());

        // Override (30) prunes it.
        $this->artisan('logging:prune-domain-logs --days=30')->assertSuccessful();
        $this->assertSame(0, DB::table('domain_logs')->count());
    }

    #[Test]
    public function it_refuses_a_zero_or_negative_retention_window(): void
    {
        $this->log('ancient', Carbon::now()->subDays(365));

        $this->artisan('logging:prune-domain-logs --days=0')->assertFailed();

        // Nothing was deleted — refusing the invalid input is the whole point.
        $this->assertSame(1, DB::table('domain_logs')->count());
    }

    private function log(string $message, Carbon $createdAt): void
    {
        DB::table('domain_logs')->insert([
            'level' => 'info',
            'channel' => 'commerce',
            'message' => $message,
            'context' => json_encode([]),
            'created_at' => $createdAt,
        ]);
    }
}
