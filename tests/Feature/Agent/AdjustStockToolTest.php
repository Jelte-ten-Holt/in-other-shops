<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Agent\Tools\AdjustStock;
use InOtherShops\Inventory\Events\StockAdjusted;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Tests\Stubs\TestBrowsable;
use InOtherShops\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class AdjustStockToolTest extends TestCase
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

        request()->attributes->set('agent.is_admin', true);
    }

    #[Test]
    public function it_adds_stock_and_returns_movement_detail(): void
    {
        $browsable = TestBrowsable::factory()->create(['slug' => 'thing']);
        StockItem::factory()->create([
            'stockable_type' => 'test_browsable',
            'stockable_id' => $browsable->id,
            'stock_level' => 4,
        ]);

        $result = app(AdjustStock::class)([
            'type' => 'browsable',
            'slug' => 'thing',
            'delta' => 6,
            'reason' => 'restock',
            'description' => 'received shipment 2026-04-22',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['type' => 'browsable', 'slug' => 'thing'], $result['target']);
        $this->assertSame(4, $result['data']['previous_stock_level']);
        $this->assertSame(10, $result['data']['stock_level']);
        $this->assertSame(6, $result['data']['delta_applied']);
        $this->assertSame('restock', $result['data']['reason']);
        $this->assertIsInt($result['data']['movement_id']);

        $movement = StockMovement::find($result['data']['movement_id']);
        $this->assertNotNull($movement);
        $this->assertSame(6, $movement->quantity);
        $this->assertSame('agent', $movement->source);
        $this->assertSame('received shipment 2026-04-22', $movement->description);
    }

    #[Test]
    public function it_defaults_reason_to_restock(): void
    {
        $browsable = TestBrowsable::factory()->create(['slug' => 'default-reason']);

        $result = app(AdjustStock::class)([
            'type' => 'browsable',
            'slug' => 'default-reason',
            'delta' => 1,
        ]);

        $this->assertSame('restock', $result['data']['reason']);
    }

    #[Test]
    public function it_dispatches_stock_adjusted(): void
    {
        Event::fake([StockAdjusted::class]);

        TestBrowsable::factory()->create(['slug' => 'eventing']);

        app(AdjustStock::class)([
            'type' => 'browsable',
            'slug' => 'eventing',
            'delta' => 2,
        ]);

        Event::assertDispatched(StockAdjusted::class);
    }

    #[Test]
    public function it_returns_not_found_when_slug_missing(): void
    {
        $result = app(AdjustStock::class)([
            'type' => 'browsable',
            'slug' => 'ghost',
            'delta' => 1,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(['type' => 'browsable', 'slug' => 'ghost'], $result['target']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    #[Test]
    public function it_throws_on_zero_delta(): void
    {
        TestBrowsable::factory()->create(['slug' => 'thing']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Delta must be >= 1/');

        app(AdjustStock::class)([
            'type' => 'browsable',
            'slug' => 'thing',
            'delta' => 0,
        ]);
    }

    #[Test]
    public function it_throws_on_negative_delta(): void
    {
        TestBrowsable::factory()->create(['slug' => 'thing']);

        $this->expectException(InvalidArgumentException::class);

        app(AdjustStock::class)([
            'type' => 'browsable',
            'slug' => 'thing',
            'delta' => -3,
        ]);
    }

    #[Test]
    public function it_throws_on_unknown_reason(): void
    {
        TestBrowsable::factory()->create(['slug' => 'thing']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid reason "sold"/');

        app(AdjustStock::class)([
            'type' => 'browsable',
            'slug' => 'thing',
            'delta' => 1,
            'reason' => 'sold',
        ]);
    }

    #[Test]
    public function it_throws_on_unknown_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AdjustStock::class)([
            'type' => 'mystery',
            'slug' => 'whatever',
            'delta' => 1,
        ]);
    }

    #[Test]
    public function non_admin_caller_is_forbidden(): void
    {
        TestBrowsable::factory()->create(['slug' => 'thing']);

        request()->attributes->set('agent.is_admin', false);

        $result = app(AdjustStock::class)([
            'type' => 'browsable',
            'slug' => 'thing',
            'delta' => 1,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('forbidden', $result['error']['code']);
        $this->assertSame(['type' => 'browsable', 'slug' => 'thing'], $result['target']);
        $this->assertSame(0, StockMovement::query()->count());
    }
}
