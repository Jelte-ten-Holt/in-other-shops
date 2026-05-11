<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Commands;

use Illuminate\Console\Command;
use InOtherShops\Payment\Models\WebhookEvent;

/**
 * Audit L4 — the `webhook_events` table is append-only (idempotency ledger,
 * one row per delivery). Without a prune step it grows unbounded over the
 * life of the shop. Retention defaults to 90 days; configure via
 * `payment.webhook_retention_days`. Schedule daily.
 */
final class PrunePaymentWebhookEventsCommand extends Command
{
    protected $signature = 'payment:prune-webhook-events {--days= : Override the configured retention window}';

    protected $description = 'Delete webhook idempotency rows older than the configured retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('payment.webhook_retention_days', 90));

        if ($days < 1) {
            $this->error('Retention window must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $deleted = WebhookEvent::query()
            ->where('processed_at', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} webhook event row(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
