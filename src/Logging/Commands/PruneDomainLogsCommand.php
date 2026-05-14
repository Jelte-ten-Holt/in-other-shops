<?php

declare(strict_types=1);

namespace InOtherShops\Logging\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The `domain_logs` table is an append-only observability store — domain
 * events plus bridged application errors. Without a prune step it grows
 * unbounded. It is an *echo*, not a system of record (the StockMovement
 * ledger, orders, and payments tables hold the durable history), so old
 * rows are deleted outright — no archive. Retention defaults to 90 days;
 * configure via `domain-log.retention_days`. Runs daily on the scheduler
 * when `domain-log.schedule.enabled` is true.
 */
final class PruneDomainLogsCommand extends Command
{
    protected $signature = 'logging:prune-domain-logs {--days= : Override the configured retention window}';

    protected $description = 'Delete domain_logs rows older than the configured retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('domain-log.retention_days', 90));

        if ($days < 1) {
            $this->error('Retention window must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $deleted = DB::table('domain_logs')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} domain log row(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
