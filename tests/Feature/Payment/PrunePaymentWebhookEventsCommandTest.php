<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InOtherShops\Payment\Models\WebhookEvent;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Audit L4 — `webhook_events` is append-only. Without retention it grows
 * unbounded. The prune command keeps the table bounded; the retention floor
 * must outlive the longest gateway retry window so a late retry can't slip
 * through after the idempotency row has been pruned.
 */
final class PrunePaymentWebhookEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_deletes_rows_older_than_the_configured_retention(): void
    {
        config()->set('payment.webhook_retention_days', 30);

        $old = WebhookEvent::factory()->create([
            'event_id' => 'old_evt',
            'processed_at' => Carbon::now()->subDays(31),
        ]);
        $fresh = WebhookEvent::factory()->create([
            'event_id' => 'fresh_evt',
            'processed_at' => Carbon::now()->subDays(29),
        ]);

        $this->artisan('payment:prune-webhook-events')
            ->expectsOutputToContain('Deleted 1 webhook event row(s) older than 30 day(s).')
            ->assertSuccessful();

        $this->assertNull(WebhookEvent::query()->find($old->id));
        $this->assertNotNull(WebhookEvent::query()->find($fresh->id));
    }

    #[Test]
    public function it_accepts_a_days_override(): void
    {
        config()->set('payment.webhook_retention_days', 90);

        WebhookEvent::factory()->create([
            'event_id' => 'evt_45_days_old',
            'processed_at' => Carbon::now()->subDays(45),
        ]);

        // Default (90) keeps the row.
        $this->artisan('payment:prune-webhook-events')->assertSuccessful();
        $this->assertSame(1, WebhookEvent::query()->count());

        // Override (30) prunes it.
        $this->artisan('payment:prune-webhook-events --days=30')->assertSuccessful();
        $this->assertSame(0, WebhookEvent::query()->count());
    }

    #[Test]
    public function it_refuses_a_zero_or_negative_retention_window(): void
    {
        WebhookEvent::factory()->create(['processed_at' => Carbon::now()->subDays(365)]);

        $this->artisan('payment:prune-webhook-events --days=0')->assertFailed();

        // Nothing was deleted — refusing the invalid input is the whole point.
        $this->assertSame(1, WebhookEvent::query()->count());
    }
}
