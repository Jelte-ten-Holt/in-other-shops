<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\Tools\GetStockLevel;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Tests\Stubs\TestBrowsable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class GetStockLevelToolTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('storefront.models', [
            'browsable' => TestBrowsable::class,
        ]);
    }

    #[Test]
    public function it_returns_stock_level_and_availability(): void
    {
        $browsable = TestBrowsable::factory()->create(['slug' => 'stocked-thing']);

        StockItem::factory()->create([
            'stockable_type' => 'test_browsable',
            'stockable_id' => $browsable->id,
            'stock_level' => 7,
        ]);

        $result = app(GetStockLevel::class)(['type' => 'browsable', 'slug' => 'stocked-thing']);

        $this->assertTrue($result['ok']);
        $this->assertSame(['type' => 'browsable', 'slug' => 'stocked-thing'], $result['target']);
        $this->assertSame(7, $result['data']['stock_level']);
        $this->assertTrue($result['data']['in_stock']);
    }

    #[Test]
    public function it_reports_zero_when_there_is_no_stock_record(): void
    {
        TestBrowsable::factory()->create(['slug' => 'unlinked-thing']);

        $result = app(GetStockLevel::class)(['type' => 'browsable', 'slug' => 'unlinked-thing']);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['data']['stock_level']);
        $this->assertFalse($result['data']['in_stock']);
    }

    #[Test]
    public function it_returns_not_found_for_unknown_slug(): void
    {
        $result = app(GetStockLevel::class)(['type' => 'browsable', 'slug' => 'missing']);

        $this->assertFalse($result['ok']);
        $this->assertSame(['type' => 'browsable', 'slug' => 'missing'], $result['target']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    #[Test]
    public function it_throws_on_unknown_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(GetStockLevel::class)(['type' => 'mystery', 'slug' => 'whatever']);
    }
}
