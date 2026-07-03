<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Agent\Tools\ListStockLevels;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Tests\Stubs\TestBrowsable;
use InOtherShops\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class ListStockLevelsToolTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('storefront.models', [
            'browsable' => TestBrowsable::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Exact quantities + low_threshold are admin-only (T-SEC3); the shape
        // tests below exercise the admin view. Non-admin has its own tests.
        request()->attributes->set('agent.is_admin', true);
    }

    #[Test]
    public function it_lists_stock_levels_for_a_browsable_type(): void
    {
        $a = TestBrowsable::factory()->create(['slug' => 'a']);
        StockItem::factory()->withLevel(10)->create([
            'stockable_type' => 'test_browsable',
            'stockable_id' => $a->id,
        ]);

        $b = TestBrowsable::factory()->create(['slug' => 'b']);
        StockItem::factory()->withLevel(0)->create([
            'stockable_type' => 'test_browsable',
            'stockable_id' => $b->id,
        ]);

        $result = app(ListStockLevels::class)(['type' => 'browsable']);

        $this->assertTrue($result['ok']);
        $slugs = array_column($result['data'], 'slug');
        $this->assertContains('a', $slugs);
        $this->assertContains('b', $slugs);
    }

    #[Test]
    public function each_row_carries_slug_name_stock_level_in_stock_and_tracks_stock(): void
    {
        $thing = TestBrowsable::factory()->create(['slug' => 'thing-1', 'name' => 'Thing One']);
        StockItem::factory()->withLevel(3)->create([
            'stockable_type' => 'test_browsable',
            'stockable_id' => $thing->id,
        ]);

        $result = app(ListStockLevels::class)(['type' => 'browsable']);

        $row = collect($result['data'])->firstWhere('slug', 'thing-1');

        $this->assertSame('thing-1', $row['slug']);
        $this->assertSame('Thing One', $row['name']);
        $this->assertSame(3, $row['stock_level']);
        $this->assertTrue($row['in_stock']);
        $this->assertTrue($row['tracks_stock']);
    }

    #[Test]
    public function low_threshold_returns_only_items_at_or_below_and_sorts_ascending(): void
    {
        $hi = TestBrowsable::factory()->create(['slug' => 'hi']);
        StockItem::factory()->withLevel(50)->create([
            'stockable_type' => 'test_browsable',
            'stockable_id' => $hi->id,
        ]);

        $mid = TestBrowsable::factory()->create(['slug' => 'mid']);
        StockItem::factory()->withLevel(4)->create([
            'stockable_type' => 'test_browsable',
            'stockable_id' => $mid->id,
        ]);

        $low = TestBrowsable::factory()->create(['slug' => 'low']);
        StockItem::factory()->withLevel(1)->create([
            'stockable_type' => 'test_browsable',
            'stockable_id' => $low->id,
        ]);

        $result = app(ListStockLevels::class)(['type' => 'browsable', 'low_threshold' => 5]);

        $slugs = array_column($result['data'], 'slug');
        $this->assertSame(['low', 'mid'], $slugs);
    }

    #[Test]
    public function low_threshold_excludes_items_with_no_stock_item_row(): void
    {
        TestBrowsable::factory()->create(['slug' => 'no-stock-row']);

        $result = app(ListStockLevels::class)(['type' => 'browsable', 'low_threshold' => 10]);

        $slugs = array_column($result['data'], 'slug');
        $this->assertNotContains('no-stock-row', $slugs);
    }

    #[Test]
    public function pagination_meta_is_present(): void
    {
        for ($i = 0; $i < 5; $i++) {
            TestBrowsable::factory()->create();
        }

        $result = app(ListStockLevels::class)(['type' => 'browsable', 'per_page' => 2, 'page' => 2]);

        $this->assertSame(2, $result['meta']['current_page']);
        $this->assertSame(2, $result['meta']['per_page']);
        $this->assertSame(5, $result['meta']['total']);
        $this->assertSame(3, $result['meta']['last_page']);
    }

    #[Test]
    public function unknown_type_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ListStockLevels::class)(['type' => 'mystery']);
    }

    #[Test]
    public function negative_low_threshold_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ListStockLevels::class)(['type' => 'browsable', 'low_threshold' => -1]);
    }

    #[Test]
    public function non_admin_rows_carry_availability_but_never_the_exact_stock_level(): void
    {
        request()->attributes->set('agent.is_admin', false);

        $thing = TestBrowsable::factory()->create(['slug' => 'thing-1']);
        StockItem::factory()->withLevel(3)->create([
            'stockable_type' => 'test_browsable',
            'stockable_id' => $thing->id,
        ]);

        $result = app(ListStockLevels::class)(['type' => 'browsable']);

        $this->assertTrue($result['ok']);
        $row = collect($result['data'])->firstWhere('slug', 'thing-1');
        $this->assertTrue($row['in_stock']);
        $this->assertTrue($row['tracks_stock']);
        $this->assertArrayNotHasKey('stock_level', $row);
    }

    #[Test]
    public function non_admin_low_threshold_is_forbidden(): void
    {
        // low_threshold is a quantity oracle — a non-admin could binary-search
        // exact levels through it even with stock_level omitted from the rows.
        request()->attributes->set('agent.is_admin', false);

        TestBrowsable::factory()->create(['slug' => 'thing-1']);

        $result = app(ListStockLevels::class)(['type' => 'browsable', 'low_threshold' => 5]);

        $this->assertFalse($result['ok']);
        $this->assertSame('forbidden', $result['error']['code']);
    }
}
