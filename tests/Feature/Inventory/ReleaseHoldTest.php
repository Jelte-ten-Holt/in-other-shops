<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Inventory\Actions\AdjustHold;
use InOtherShops\Inventory\Actions\ReleaseHold;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\Support\RecordsLockOrder;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The hold's Release button (stock-holds brief §4.1).
 */
final class ReleaseHoldTest extends TestCase
{
    use RecordsLockOrder;
    use RefreshDatabase;

    #[Test]
    public function releasing_a_hold_gives_back_everything_it_holds_and_stamps_it(): void
    {
        $hold = StockHold::factory()->create(['name' => 'Markt']);
        $bowl = $this->stockable(5);
        $cup = $this->stockable(4);
        app(AdjustHold::class)($hold, $bowl, 2);
        app(AdjustHold::class)($hold, $cup, 1);

        app(ReleaseHold::class)($hold);

        $this->assertNotNull($hold->fresh()->released_at);
        $this->assertSame(0, $hold->pendingReservations()->count());
        $this->assertSame(5, $this->level($bowl));
        $this->assertSame(4, $this->level($cup));
        $this->assertSame(2, StockMovement::query()->where('description', 'Hold Markt released')->count());
    }

    #[Test]
    public function releasing_twice_moves_no_stock_and_keeps_the_first_stamp(): void
    {
        $hold = StockHold::factory()->create();
        $bowl = $this->stockable(5);
        app(AdjustHold::class)($hold, $bowl, 2);
        app(ReleaseHold::class)($hold);
        $stamp = $hold->fresh()->released_at;
        $movements = StockMovement::query()->count();
        $this->travel(1)->hours();

        app(ReleaseHold::class)($hold->fresh());

        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertEquals($stamp, $hold->fresh()->released_at);
        $this->assertSame(5, $this->level($bowl));
    }

    #[Test]
    public function releasing_again_sweeps_a_row_that_reached_a_released_hold(): void
    {
        // No path in the package lets this happen (every hold actor reads
        // released_at under the hold-row lock); reconcile names it an error and
        // Release again is the repair.
        $hold = StockHold::factory()->released()->create();
        $bowl = $this->stockable(5);
        $stray = app(ReserveStock::class)($bowl, 1, reference: $hold, expires: false);

        app(ReleaseHold::class)($hold);

        $this->assertSame(ReservationStatus::Released, $stray->fresh()->status);
        $this->assertSame(5, $this->level($bowl));
    }

    #[Test]
    public function it_locks_the_hold_then_its_stock_items_then_its_rows(): void
    {
        $hold = StockHold::factory()->create();
        app(AdjustHold::class)($hold, $this->stockable(5), 2);
        app(AdjustHold::class)($hold, $this->stockable(5), 1);

        $this->recordLockOrder();
        app(ReleaseHold::class)($hold);

        $this->assertLocksTakenInOrder(['stock_holds', 'stock_items', 'stock_reservations']);
    }

    private function stockable(int $level): TestStockable
    {
        $stockable = TestStockable::factory()->create();
        StockItem::factory()->for($stockable, 'stockable')->withLevel($level)->create();

        return $stockable;
    }

    private function level(TestStockable $stockable): int
    {
        return (int) $stockable->stockItem()->first()->stock_level;
    }
}
