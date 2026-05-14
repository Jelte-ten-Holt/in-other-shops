<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Agent\Tools\GetRecentProblems;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The data source behind the logging dashboard. Admin-only; returns recent
 * domain_logs entries grouped by a fingerprint of the message — never the
 * raw context, and with emails + digit runs scrubbed out of the fingerprint
 * so identity-linking data cannot reach the transcript.
 */
final class GetRecentProblemsToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        request()->attributes->set('agent.is_admin', true);
    }

    #[Test]
    public function it_is_admin_only(): void
    {
        request()->attributes->set('agent.is_admin', false);

        $result = app(GetRecentProblems::class)([]);

        $this->assertFalse($result['ok']);
        $this->assertSame('forbidden', $result['error']['code']);
    }

    #[Test]
    public function it_groups_entries_that_differ_only_by_a_number_into_one_row(): void
    {
        $this->log(['message' => 'No order with id 1.', 'level' => 'error', 'channel' => 'app']);
        $this->log(['message' => 'No order with id 2.', 'level' => 'error', 'channel' => 'app']);
        $this->log(['message' => 'No order with id 37.', 'level' => 'error', 'channel' => 'app']);

        $result = app(GetRecentProblems::class)([]);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('No order with id N.', $result['data'][0]['message']);
        $this->assertSame(3, $result['data'][0]['count']);
        $this->assertSame('app', $result['data'][0]['channel']);
        $this->assertSame('error', $result['data'][0]['level']);
    }

    #[Test]
    public function it_excludes_entries_outside_the_since_hours_window(): void
    {
        $this->log(['message' => 'recent', 'level' => 'error', 'created_at' => now()->subHour()]);
        $this->log(['message' => 'ancient', 'level' => 'error', 'created_at' => now()->subDays(10)]);

        $result = app(GetRecentProblems::class)(['since_hours' => 24]);

        $this->assertCount(1, $result['data']);
        $this->assertSame('recent', $result['data'][0]['message']);
    }

    #[Test]
    public function it_floors_by_level_and_can_be_lowered_to_show_activity(): void
    {
        $this->log(['message' => 'an error', 'level' => 'error']);
        $this->log(['message' => 'just activity', 'level' => 'info']);

        // Default floor is "warning" — info-level activity is excluded.
        $default = app(GetRecentProblems::class)([]);
        $this->assertCount(1, $default['data']);
        $this->assertSame('an error', $default['data'][0]['message']);

        // Lower the floor to "info" — the activity stream now shows.
        $lowered = app(GetRecentProblems::class)(['level' => 'info']);
        $this->assertCount(2, $lowered['data']);
    }

    #[Test]
    public function it_filters_by_channel(): void
    {
        $this->log(['message' => 'app error', 'level' => 'error', 'channel' => 'app']);
        $this->log(['message' => 'commerce error', 'level' => 'error', 'channel' => 'commerce']);

        $result = app(GetRecentProblems::class)(['channel' => 'app']);

        $this->assertCount(1, $result['data']);
        $this->assertSame('app error', $result['data'][0]['message']);
    }

    #[Test]
    public function it_scrubs_emails_from_the_fingerprint(): void
    {
        $this->log(['message' => 'User billy@example.com not found.', 'level' => 'error']);

        $result = app(GetRecentProblems::class)([]);

        $this->assertSame('User [email] not found.', $result['data'][0]['message']);
    }

    #[Test]
    public function it_orders_groups_by_count_descending(): void
    {
        $this->log(['message' => 'rare', 'level' => 'error']);
        $this->log(['message' => 'common', 'level' => 'error']);
        $this->log(['message' => 'common', 'level' => 'error']);

        $result = app(GetRecentProblems::class)([]);

        $this->assertSame('common', $result['data'][0]['message']);
        $this->assertSame(2, $result['data'][0]['count']);
        $this->assertSame('rare', $result['data'][1]['message']);
    }

    /** @param array<string, mixed> $attributes */
    private function log(array $attributes): void
    {
        DB::table('domain_logs')->insert(array_merge([
            'level' => 'error',
            'channel' => 'app',
            'message' => 'something',
            'context' => json_encode([]),
            'created_at' => now(),
        ], $attributes));
    }
}
