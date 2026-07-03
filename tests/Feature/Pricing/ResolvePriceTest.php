<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\ResolvePrice;
use InOtherShops\Pricing\Models\PriceList;
use InOtherShops\Tests\Stubs\TestPriceable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Read-path that the cart, storefront, and agent all flow through. The
 * action's contract: return the most-applicable Price for a (priceable,
 * currency, quantity, ?priceList) tuple, falling back from the requested
 * price list to the base list if no match exists in the requested one.
 */
final class ResolvePriceTest extends TestCase
{
    use RefreshDatabase;

    private ResolvePrice $resolve;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolve = new ResolvePrice;
    }

    #[Test]
    public function it_returns_the_base_price_when_no_price_list_is_passed(): void
    {
        $priceable = TestPriceable::factory()->create();
        $price = $priceable->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 1500,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ]);

        $resolved = ($this->resolve)($priceable, Currency::EUR);

        $this->assertNotNull($resolved);
        $this->assertSame($price->id, $resolved->id);
    }

    #[Test]
    public function it_returns_null_when_no_price_exists_in_the_requested_currency(): void
    {
        $priceable = TestPriceable::factory()->create();
        $priceable->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 1500,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ]);

        $this->assertNull(($this->resolve)($priceable, Currency::USD));
    }

    #[Test]
    public function it_returns_null_when_priceable_has_no_prices_at_all(): void
    {
        $priceable = TestPriceable::factory()->create();

        $this->assertNull(($this->resolve)($priceable, Currency::EUR));
    }

    #[Test]
    public function it_returns_the_highest_minimum_quantity_tier_that_the_request_satisfies(): void
    {
        // Tiered pricing: 1+ at 1500, 5+ at 1300, 10+ at 1100. A request for
        // qty=7 must resolve to the 5+ tier (1300) — not the 1+ tier (would
        // overcharge) and not the 10+ tier (would undercut).
        $priceable = TestPriceable::factory()->create();

        $tier1 = $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1500, 'minimum_quantity' => 1, 'price_list_id' => null,
        ]);
        $tier5 = $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1300, 'minimum_quantity' => 5, 'price_list_id' => null,
        ]);
        $tier10 = $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1100, 'minimum_quantity' => 10, 'price_list_id' => null,
        ]);

        $resolved = ($this->resolve)($priceable, Currency::EUR, quantity: 7);

        $this->assertNotNull($resolved);
        $this->assertSame($tier5->id, $resolved->id);
    }

    #[Test]
    public function it_does_not_apply_a_tier_whose_minimum_quantity_exceeds_the_request(): void
    {
        $priceable = TestPriceable::factory()->create();
        $tier1 = $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1500, 'minimum_quantity' => 1, 'price_list_id' => null,
        ]);
        $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1100, 'minimum_quantity' => 10, 'price_list_id' => null,
        ]);

        $resolved = ($this->resolve)($priceable, Currency::EUR, quantity: 4);

        $this->assertNotNull($resolved);
        $this->assertSame($tier1->id, $resolved->id);
    }

    #[Test]
    public function it_prefers_a_price_in_the_requested_price_list_over_the_base_list(): void
    {
        $priceable = TestPriceable::factory()->create();
        $list = PriceList::factory()->create(['name' => 'wholesale']);

        $basePrice = $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1500, 'minimum_quantity' => 1, 'price_list_id' => null,
        ]);
        $listPrice = $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1200, 'minimum_quantity' => 1, 'price_list_id' => $list->id,
        ]);

        $resolved = ($this->resolve)($priceable, Currency::EUR, priceList: $list);

        $this->assertNotNull($resolved);
        $this->assertSame($listPrice->id, $resolved->id,
            'When a price list is requested, its price wins over the base price.');
    }

    #[Test]
    public function it_falls_back_to_the_base_list_when_the_requested_list_has_no_matching_price(): void
    {
        // Critical for the customer-group flow: a wholesale customer has a
        // wholesale price list assigned, but if the catalog only has a base
        // price for some items, the wholesale lookup must fall back instead
        // of returning null.
        $priceable = TestPriceable::factory()->create();
        $list = PriceList::factory()->create(['name' => 'wholesale']);

        $basePrice = $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1500, 'minimum_quantity' => 1, 'price_list_id' => null,
        ]);

        $resolved = ($this->resolve)($priceable, Currency::EUR, priceList: $list);

        $this->assertNotNull($resolved);
        $this->assertSame($basePrice->id, $resolved->id,
            'Missing wholesale price must fall back to the base list, not return null.');
    }

    #[Test]
    public function the_fallback_to_base_list_respects_currency(): void
    {
        // Fallback must NOT cross currencies. A USD wholesale lookup with
        // only an EUR base price returns null, not the EUR price.
        $priceable = TestPriceable::factory()->create();
        $list = PriceList::factory()->create(['name' => 'wholesale']);

        $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1500, 'minimum_quantity' => 1, 'price_list_id' => null,
        ]);

        $this->assertNull(
            ($this->resolve)($priceable, Currency::USD, priceList: $list),
            'Fallback to base list must not silently swap currencies.',
        );
    }

    #[Test]
    public function the_fallback_to_base_list_respects_minimum_quantity(): void
    {
        // The fallback path is a separate query — assert it ALSO honors the
        // tiered-quantity rule, not just the price_list filter.
        $priceable = TestPriceable::factory()->create();
        $list = PriceList::factory()->create(['name' => 'wholesale']);

        $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1500, 'minimum_quantity' => 1, 'price_list_id' => null,
        ]);
        $bulkBase = $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1100, 'minimum_quantity' => 10, 'price_list_id' => null,
        ]);

        $resolved = ($this->resolve)($priceable, Currency::EUR, quantity: 12, priceList: $list);

        $this->assertNotNull($resolved);
        $this->assertSame($bulkBase->id, $resolved->id);
    }

    #[Test]
    public function trait_helper_priceFor_delegates_to_resolve_price(): void
    {
        // The InteractsWithPrices trait method is the public surface every
        // consumer uses. Without this test the trait's wiring to ResolvePrice
        // (via app()) could regress silently — e.g. if the trait stopped
        // passing the priceList parameter through.
        $priceable = TestPriceable::factory()->create();
        $list = PriceList::factory()->create(['name' => 'wholesale']);

        $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1500, 'minimum_quantity' => 1, 'price_list_id' => null,
        ]);
        $listPrice = $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1200, 'minimum_quantity' => 1, 'price_list_id' => $list->id,
        ]);

        $this->assertSame($listPrice->id, $priceable->priceFor(Currency::EUR, $list)->id);
    }

    #[Test]
    public function it_resolves_from_a_loaded_relation_without_issuing_a_query(): void
    {
        // SCALE-2: when the caller eager-loaded `prices` (the catalogue does),
        // resolving must not hit the DB again — the whole point of `with('prices')`.
        $priceable = TestPriceable::factory()->create();
        $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1500, 'minimum_quantity' => 1, 'price_list_id' => null,
        ]);

        $loaded = TestPriceable::query()->with('prices')->findOrFail($priceable->id);

        DB::connection()->enableQueryLog();
        $resolved = ($this->resolve)($loaded, Currency::EUR);
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();

        $this->assertNotNull($resolved);
        $this->assertSame(1500, $resolved->amount);
        $this->assertCount(0, $queries, 'A loaded prices relation must resolve in-memory with no extra query.');
    }

    #[Test]
    public function the_loaded_relation_path_matches_the_query_semantics(): void
    {
        // Parity: the in-memory pick must honour price-list preference, the
        // base-list fallback, the tiered minimum_quantity rule, and currency
        // isolation — exactly as the query path does.
        $priceable = TestPriceable::factory()->create();
        $list = PriceList::factory()->create(['name' => 'wholesale']);

        $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1500, 'minimum_quantity' => 1, 'price_list_id' => null,
        ]);
        $bulkBase = $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1100, 'minimum_quantity' => 10, 'price_list_id' => null,
        ]);
        $listPrice = $priceable->prices()->create([
            'currency' => Currency::EUR->value, 'amount' => 1200, 'minimum_quantity' => 1, 'price_list_id' => $list->id,
        ]);

        $loaded = TestPriceable::query()->with('prices')->findOrFail($priceable->id);

        $this->assertSame($listPrice->id, ($this->resolve)($loaded, Currency::EUR, priceList: $list)->id,
            'Requested price list wins from the loaded relation.');
        $this->assertSame($bulkBase->id, ($this->resolve)($loaded, Currency::EUR, quantity: 12)->id,
            'Highest satisfied tier wins from the loaded relation.');
        $this->assertNull(($this->resolve)($loaded, Currency::USD),
            'Currency isolation holds from the loaded relation.');
    }
}
