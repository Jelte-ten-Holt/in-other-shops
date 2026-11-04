<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Inventory\Actions\AdjustHold;
use InOtherShops\Inventory\Actions\ReserveFromHold;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Exceptions\InsufficientStockException;
use InOtherShops\Inventory\Exceptions\InventoryException;
use InOtherShops\Inventory\Exceptions\StockHoldReleasedException;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\Support\RecordsLockOrder;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The hold form's save: "set each product to N under the lock" (stock-holds
 * brief §4.1).
 */
final class AdjustHoldTest extends TestCase
{
    use RecordsLockOrder;
    use RefreshDatabase;

    #[Test]
    public function holding_a_product_reserves_it_for_the_hold_with_no_ttl(): void
    {
        config(['inventory.reservation_ttl' => 30]);
        $hold = StockHold::factory()->create(['name' => 'Markt 15 Nov']);
        $bowl = $this->stockable(5);

        $held = app(AdjustHold::class)($hold, $bowl, 2);

        $this->assertSame(2, $held);
        $this->assertSame(3, $this->level($bowl));
        $reservation = $hold->pendingReservations()->sole();
        $this->assertSame(2, $reservation->quantity);
        $this->assertNull($reservation->reserved_until);
        $this->assertSame('dashboard', $reservation->source);
        $this->assertSame('Held for Markt 15 Nov', $reservation->reserveMovement->description);
        $this->assertSame(2, $hold->heldQuantity($bowl));
    }

    #[Test]
    public function raising_a_hold_reserves_only_the_difference(): void
    {
        $hold = StockHold::factory()->create();
        $bowl = $this->stockable(5);
        app(AdjustHold::class)($hold, $bowl, 2);
        $movementsBefore = StockMovement::query()->count();

        app(AdjustHold::class)($hold, $bowl, 3);

        $this->assertSame(3, $hold->heldQuantity($bowl));
        $this->assertSame(2, $this->level($bowl));
        $this->assertSame($movementsBefore + 1, StockMovement::query()->count(), 'An increase is one reserve.');
    }

    #[Test]
    public function lowering_a_hold_releases_and_re_reserves_what_stays(): void
    {
        $hold = StockHold::factory()->create(['name' => 'Markt 15 Nov']);
        $bowl = $this->stockable(5);
        app(AdjustHold::class)($hold, $bowl, 3);
        $movementsBefore = StockMovement::query()->count();

        app(AdjustHold::class)($hold, $bowl, 1);

        $this->assertSame(1, $hold->heldQuantity($bowl));
        $this->assertSame(4, $this->level($bowl));
        $newMovements = StockMovement::query()->orderBy('id')->skip($movementsBefore)->take(10)->get();
        $this->assertSame([StockMovementReason::Released, StockMovementReason::Reserved], $newMovements->pluck('reason')->all());
        $this->assertSame('Taken off hold Markt 15 Nov', $newMovements[0]->description);
        $this->assertNull($hold->pendingReservations()->sole()->reserved_until);
    }

    #[Test]
    public function setting_a_hold_to_zero_gives_everything_back(): void
    {
        $hold = StockHold::factory()->create();
        $bowl = $this->stockable(5);
        app(AdjustHold::class)($hold, $bowl, 3);

        app(AdjustHold::class)($hold, $bowl, 0);

        $this->assertSame(0, $hold->pendingReservations()->count());
        $this->assertSame(5, $this->level($bowl));
    }

    #[Test]
    public function a_drain_between_page_load_and_save_still_lands_on_the_number_typed(): void
    {
        // The page loaded with 3 held; the admin types 2. Meanwhile a market
        // sale moved 2 out. The save reads 1 under the lock and reserves 1.
        $hold = StockHold::factory()->create();
        $bowl = $this->stockable(5);
        app(AdjustHold::class)($hold, $bowl, 3);
        app(ReserveFromHold::class)($hold, $bowl, 2, TestStockable::factory()->create(), 'Moved from hold to order 1');

        app(AdjustHold::class)($hold, $bowl, 2);

        $this->assertSame(2, $hold->heldQuantity($bowl));
        $this->assertSame(1, $this->level($bowl), '5 − 2 sold − 2 held.');
    }

    #[Test]
    public function holding_more_than_is_in_stock_is_refused_without_writing(): void
    {
        $hold = StockHold::factory()->create();
        $bowl = $this->stockable(1);

        $this->assertRefusedWithoutWriting(InsufficientStockException::class, fn () => app(AdjustHold::class)($hold, $bowl, 2), $bowl, 1);
    }

    #[Test]
    public function a_backorderable_product_at_negative_stock_cannot_be_held(): void
    {
        // A hold is pieces you pack: rejectOversell is forced on, whatever
        // allowsBackorder() says.
        $hold = StockHold::factory()->create();
        $madeToOrder = TestStockable::factory()->backorderable()->create();
        StockItem::factory()->for($madeToOrder, 'stockable')->withLevel(-2)->create();

        $this->assertRefusedWithoutWriting(InsufficientStockException::class, fn () => app(AdjustHold::class)($hold, $madeToOrder, 1), $madeToOrder, -2);
    }

    #[Test]
    public function a_released_hold_cannot_change(): void
    {
        $hold = StockHold::factory()->released()->create();
        $bowl = $this->stockable(5);

        $this->assertRefusedWithoutWriting(StockHoldReleasedException::class, fn () => app(AdjustHold::class)($hold, $bowl, 1), $bowl, 5);
    }

    #[Test]
    public function a_product_that_does_not_track_stock_cannot_be_held(): void
    {
        $hold = StockHold::factory()->create();
        $download = TestStockable::factory()->untracked()->create();

        $this->expectException(InventoryException::class);

        try {
            app(AdjustHold::class)($hold, $download, 1);
        } finally {
            $this->assertSame(0, StockReservation::query()->count());
        }
    }

    #[Test]
    public function it_locks_the_hold_then_the_stock_item_then_the_holds_rows(): void
    {
        $hold = StockHold::factory()->create();
        $bowl = $this->stockable(5);
        app(AdjustHold::class)($hold, $bowl, 3);

        $this->recordLockOrder();
        app(AdjustHold::class)($hold, $bowl, 1);

        $this->assertLocksTakenInOrder(['stock_holds', 'stock_items', 'stock_reservations']);
    }

    private function assertRefusedWithoutWriting(string $exception, \Closure $act, TestStockable $stockable, int $level): void
    {
        try {
            $act();
            $this->fail("Expected {$exception}.");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);
        }

        $this->assertSame(0, StockReservation::query()->where('status', ReservationStatus::Pending)->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame($level, $this->level($stockable));
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
