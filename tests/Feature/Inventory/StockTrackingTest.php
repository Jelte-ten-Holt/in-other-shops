<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class StockTrackingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tracks_stock_defaults_to_true(): void
    {
        $stockable = TestStockable::factory()->create();

        $this->assertTrue($stockable->tracksStock());
    }

    #[Test]
    public function untracked_stockable_is_always_in_stock(): void
    {
        $stockable = TestStockable::factory()->create(['tracks_stock' => false]);

        $this->assertFalse($stockable->tracksStock());
        $this->assertSame(0, $stockable->stockLevel());
        $this->assertTrue($stockable->isInStock());
    }

    #[Test]
    public function tracked_stockable_with_zero_level_is_out_of_stock(): void
    {
        $stockable = TestStockable::factory()->create(['tracks_stock' => true]);

        $this->assertSame(0, $stockable->stockLevel());
        $this->assertFalse($stockable->isInStock());
    }
}
