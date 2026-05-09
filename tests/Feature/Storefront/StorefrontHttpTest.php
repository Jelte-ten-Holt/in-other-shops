<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Tests\Stubs\TestBrowsable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Working spec for the Storefront HTTP layer.
 *
 * Lives on the `storefront-http-archive` branch — main has the HTTP layer
 * removed (no consumer in in-other-worlds; filtering uses Inertia partial
 * reload, cart/checkout uses its own controllers). When/if a consumer
 * needs JSON-over-HTTP for HasStorefrontPresence models, merge this branch
 * back and the layer comes with passing tests.
 *
 * Covers the dynamic-route loop in Storefront/Http/Routes/api.php, the four
 * controllers, the BrowsableResource conditional shape, and the
 * SetStorefrontContext middleware's currency-from-header behaviour.
 */
final class StorefrontHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Two storefront.models entries → the dynamic-route loop must
        // register a list and a show route for each. Asserted below.
        $app['config']->set('storefront.models', [
            'browsables' => TestBrowsable::class,
            'extra-browsables' => TestBrowsable::class,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Dynamic route registration
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function it_registers_a_list_and_show_route_per_storefront_model(): void
    {
        $names = collect(app('router')->getRoutes()->getRoutesByName())->keys();

        $this->assertTrue($names->contains('storefront.browsables.index'));
        $this->assertTrue($names->contains('storefront.browsables.show'));
        $this->assertTrue($names->contains('storefront.extra-browsables.index'));
        $this->assertTrue($names->contains('storefront.extra-browsables.show'));
        $this->assertTrue($names->contains('storefront.categories.index'));
        $this->assertTrue($names->contains('storefront.categories.show'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Browsable list endpoint
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function the_list_endpoint_returns_paginated_browsables_with_the_documented_shape(): void
    {
        TestBrowsable::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        TestBrowsable::factory()->create(['name' => 'Bravo', 'slug' => 'bravo']);

        $response = $this->getJson('/api/storefront/browsables');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['type', 'name', 'slug', 'description'],
                ],
                'meta' => ['current_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('data.0.type', 'browsables');

        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function the_list_endpoint_applies_a_search_filter_against_name_and_description(): void
    {
        TestBrowsable::factory()->create(['name' => 'Sunset Mug', 'slug' => 'sunset', 'description' => 'evening colors']);
        TestBrowsable::factory()->create(['name' => 'Mountain Print', 'slug' => 'mountain', 'description' => 'morning shadows']);

        $response = $this->getJson('/api/storefront/browsables?search=evening');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('sunset', $response->json('data.0.slug'));
    }

    #[Test]
    public function the_list_endpoint_sorts_by_name_when_sort_query_is_set(): void
    {
        TestBrowsable::factory()->create(['name' => 'Zebra', 'slug' => 'zebra']);
        TestBrowsable::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);

        $response = $this->getJson('/api/storefront/browsables?sort=name');

        $this->assertSame(['alpha', 'zebra'], collect($response->json('data'))->pluck('slug')->all());
    }

    #[Test]
    public function the_list_endpoint_sorts_descending_when_sort_starts_with_minus(): void
    {
        TestBrowsable::factory()->create(['name' => 'Zebra', 'slug' => 'zebra']);
        TestBrowsable::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);

        $response = $this->getJson('/api/storefront/browsables?sort=-name');

        $this->assertSame(['zebra', 'alpha'], collect($response->json('data'))->pluck('slug')->all());
    }

    #[Test]
    public function the_list_endpoint_ignores_an_unknown_sort_field_and_falls_back_to_published_at_desc(): void
    {
        // Important: an attacker-supplied ?sort=password_hash must not
        // become an ORDER BY clause. The action whitelists fields and
        // silently falls back to the safe default.
        TestBrowsable::factory()->create(['slug' => 'older', 'published_at' => now()->subDay()]);
        TestBrowsable::factory()->create(['slug' => 'newer', 'published_at' => now()]);

        $response = $this->getJson('/api/storefront/browsables?sort=password_hash');

        $this->assertSame(['newer', 'older'], collect($response->json('data'))->pluck('slug')->all());
    }

    #[Test]
    public function the_list_endpoint_caps_per_page_at_100(): void
    {
        TestBrowsable::factory()->count(3)->create();

        $response = $this->getJson('/api/storefront/browsables?per_page=999');

        $response->assertOk();
        $this->assertSame(100, $response->json('meta.per_page'),
            'per_page must clamp to 100 to prevent unbounded queries.');
    }

    #[Test]
    public function the_list_endpoint_uses_the_configured_default_per_page(): void
    {
        config()->set('storefront.defaults.per_page', 5);

        TestBrowsable::factory()->count(3)->create();

        $response = $this->getJson('/api/storefront/browsables');

        $this->assertSame(5, $response->json('meta.per_page'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Browsable show endpoint
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function the_show_endpoint_returns_a_single_browsable_with_the_resolved_type(): void
    {
        TestBrowsable::factory()->create(['name' => 'Solo', 'slug' => 'solo', 'description' => 'one of one']);

        $response = $this->getJson('/api/storefront/browsables/solo');

        $response->assertOk()
            ->assertJsonPath('data.type', 'browsables')
            ->assertJsonPath('data.name', 'Solo')
            ->assertJsonPath('data.slug', 'solo')
            ->assertJsonPath('data.description', 'one of one');
    }

    #[Test]
    public function the_show_endpoint_returns_404_with_a_json_message_for_an_unknown_slug(): void
    {
        $response = $this->getJson('/api/storefront/browsables/does-not-exist');

        $response->assertStatus(404)
            ->assertExactJson(['message' => 'Not found.']);
    }

    #[Test]
    public function the_show_endpoint_routes_through_the_correct_model_when_multiple_storefront_models_are_configured(): void
    {
        // Both routes resolve to TestBrowsable in this test, but the route's
        // `defaults('browsable_model', ...)` injection must wire the right
        // class per route. Pin via the resource's `type` field, which the
        // BrowsableResource resolves from config('storefront.models').
        TestBrowsable::factory()->create(['slug' => 'shared']);

        $response = $this->getJson('/api/storefront/extra-browsables/shared');

        $response->assertOk();
        // BrowsableResource resolves `type` by class match; since both routes
        // map to TestBrowsable, the FIRST matching key wins. That's a
        // behaviour worth pinning so a future "last wins" change is caught.
        $this->assertSame('browsables', $response->json('data.type'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Categories list endpoint
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function the_categories_endpoint_returns_only_active_top_level_categories(): void
    {
        $taxonomy = app(\InOtherShops\Taxonomy\Models\Category::class);
        // Top-level active
        \InOtherShops\Taxonomy\Models\Category::factory()->create(['slug' => 'top-active', 'is_active' => true, 'parent_id' => null]);
        // Top-level inactive
        \InOtherShops\Taxonomy\Models\Category::factory()->create(['slug' => 'top-inactive', 'is_active' => false, 'parent_id' => null]);
        // Child (active) — must be excluded from top-level listing
        $parent = \InOtherShops\Taxonomy\Models\Category::factory()->create(['slug' => 'parent', 'is_active' => true, 'parent_id' => null]);
        \InOtherShops\Taxonomy\Models\Category::factory()->create(['slug' => 'child', 'is_active' => true, 'parent_id' => $parent->id]);

        $response = $this->getJson('/api/storefront/categories');

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('top-active', $slugs);
        $this->assertContains('parent', $slugs);
        $this->assertNotContains('top-inactive', $slugs,
            'Inactive categories must not appear in the public listing.');
        $this->assertNotContains('child', $slugs,
            'Child categories must be served via parent.children, not as top-level.');
    }

    // ─────────────────────────────────────────────────────────────────
    // Category show endpoint
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function the_category_show_endpoint_returns_404_for_unknown_slug(): void
    {
        $this->getJson('/api/storefront/categories/does-not-exist')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Not found.']);
    }

    #[Test]
    public function the_category_show_endpoint_returns_404_for_an_inactive_category(): void
    {
        \InOtherShops\Taxonomy\Models\Category::factory()->create([
            'slug' => 'hidden',
            'is_active' => false,
            'parent_id' => null,
        ]);

        $this->getJson('/api/storefront/categories/hidden')
            ->assertStatus(404,
                'Inactive categories must 404 — never serve the URL even if the row exists.');
    }

    #[Test]
    public function the_category_show_endpoint_returns_the_category_with_paginated_items(): void
    {
        $category = \InOtherShops\Taxonomy\Models\Category::factory()->create([
            'slug' => 'mugs',
            'is_active' => true,
            'parent_id' => null,
        ]);

        $response = $this->getJson('/api/storefront/categories/mugs');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'slug'],
                'items' => ['data', 'meta'],
            ])
            ->assertJsonPath('data.slug', 'mugs');
    }

    // ─────────────────────────────────────────────────────────────────
    // SetStorefrontContext middleware
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function the_currency_header_sets_the_storefront_context_currency(): void
    {
        // The middleware exposes per-request currency via the StorefrontContext
        // singleton. The cleanest probe is a request that exercises the chain
        // end-to-end and a follow-up assertion against the bound singleton —
        // which only works inside the same request. Test by inspecting the
        // bound instance via app() inside the request's after-hook is too
        // cumbersome; instead pin behaviour via a dedicated probe route.
        \Illuminate\Support\Facades\Route::middleware([
            'api',
            \InOtherShops\Storefront\Http\Middleware\SetStorefrontContext::class,
        ])->get('/storefront-currency-probe', function (): array {
            return [
                'currency' => app(\InOtherShops\Storefront\DTOs\StorefrontContext::class)->currency->value,
            ];
        });

        $this->getJson('/storefront-currency-probe', ['X-Currency' => 'EUR'])
            ->assertOk()
            ->assertJsonPath('currency', 'EUR');
    }

    #[Test]
    public function the_middleware_falls_back_to_the_first_enabled_currency_for_an_unknown_header(): void
    {
        // An attacker-controlled X-Currency must not be able to inject an
        // arbitrary string — Currency::tryFrom returns null for unknowns and
        // the middleware falls back to enabled[0].
        \Illuminate\Support\Facades\Route::middleware([
            'api',
            \InOtherShops\Storefront\Http\Middleware\SetStorefrontContext::class,
        ])->get('/storefront-currency-probe-2', function (): array {
            return [
                'currency' => app(\InOtherShops\Storefront\DTOs\StorefrontContext::class)->currency->value,
            ];
        });

        $response = $this->getJson('/storefront-currency-probe-2', ['X-Currency' => 'XXX']);

        $response->assertOk();
        $this->assertNotSame('XXX', $response->json('currency'));
    }
}
