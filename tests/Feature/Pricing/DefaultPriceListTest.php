<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Pricing\Models\PriceList;
use InOtherShops\Pricing\Pricing;
use InOtherShops\Pricing\Support\DefaultPriceListResolver;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * DRY-1 (bianka AUDIT-2026-07-04) — both consumers hand-rolled
 * `PriceList::where('is_default', true)->first()` with ad-hoc memoization
 * 11 times. `Pricing::defaultPriceList()` is the package-level replacement:
 * resolved at most once per request/container scope (scoped binding, so
 * Octane/queue workers re-resolve per request instead of caching stale),
 * with `forgetDefaultPriceList()` to re-resolve within a scope.
 */
final class DefaultPriceListTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_the_price_list_flagged_as_default(): void
    {
        PriceList::factory()->create(['name' => 'Wholesale']);
        $default = PriceList::factory()->default()->create(['name' => 'Retail']);

        $resolved = Pricing::defaultPriceList();

        $this->assertNotNull($resolved);
        $this->assertSame($default->id, $resolved->id);
    }

    #[Test]
    public function it_returns_null_when_no_price_list_is_flagged_default(): void
    {
        PriceList::factory()->create(['name' => 'Wholesale']);

        $this->assertNull(Pricing::defaultPriceList());
    }

    #[Test]
    public function it_resolves_once_per_scope_and_the_second_call_issues_no_query(): void
    {
        $default = PriceList::factory()->default()->create();

        Pricing::defaultPriceList();

        DB::connection()->enableQueryLog();
        $second = Pricing::defaultPriceList();
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();

        $this->assertSame($default->id, $second?->id);
        $this->assertCount(0, $queries, 'Repeat calls within a scope must serve the memoized default.');
    }

    #[Test]
    public function a_null_result_is_memoized_too(): void
    {
        // The no-default case must not degrade into a query per call — that
        // would silently reintroduce the N+1 the resolver exists to kill.
        $this->assertNull(Pricing::defaultPriceList());

        DB::connection()->enableQueryLog();
        $this->assertNull(Pricing::defaultPriceList());
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();

        $this->assertCount(0, $queries, 'A memoized null must not re-query.');
    }

    #[Test]
    public function forget_default_price_list_re_resolves_on_the_next_call(): void
    {
        $this->assertNull(Pricing::defaultPriceList());

        $default = PriceList::factory()->default()->create();

        $this->assertNull(Pricing::defaultPriceList(), 'Still memoized until forgotten.');

        Pricing::forgetDefaultPriceList();

        $this->assertSame($default->id, Pricing::defaultPriceList()?->id);
    }

    #[Test]
    public function a_scope_flush_resets_the_memo_like_a_new_request_would(): void
    {
        // Octane / queue workers flush scoped instances between requests/jobs;
        // forgetScopedInstances() is the same mechanism. The memo must not
        // survive it — surviving would mean serving a stale default across
        // requests.
        $this->assertNull(Pricing::defaultPriceList());

        $default = PriceList::factory()->default()->create();

        app()->forgetScopedInstances();

        $this->assertSame($default->id, Pricing::defaultPriceList()?->id,
            'A fresh scope must re-resolve instead of inheriting the previous scope\'s memo.');
    }

    #[Test]
    public function the_resolver_is_container_scoped_not_a_plain_singleton(): void
    {
        // Pin the binding kind: a plain singleton() would survive Octane's
        // per-request flush and serve a stale default forever. scoped
        // instances are exactly the set forgetScopedInstances() clears.
        $before = app(DefaultPriceListResolver::class);
        $this->assertSame($before, app(DefaultPriceListResolver::class), 'Within a scope, one shared instance.');

        app()->forgetScopedInstances();

        $this->assertNotSame($before, app(DefaultPriceListResolver::class),
            'A scope flush must produce a fresh resolver instance.');
    }
}
