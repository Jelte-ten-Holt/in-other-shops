<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Inventory\Actions\AdjustHold;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ReleaseExpiredReservations;
use InOtherShops\Inventory\Actions\ReleaseHold;
use InOtherShops\Inventory\Actions\ReserveFromHold;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Exceptions\StockHoldReleasedException;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\Stubs\TestStockableLocalizable;
use InOtherShops\Tests\Support\RecordsLockOrder;
use InOtherShops\Tests\TestCase;
use InOtherShops\Translation\Models\LocaleGroup;
use PHPUnit\Framework\Attributes\Test;

/**
 * Moving units from a hold into an order (stock-holds brief §4.2). The order
 * here is any model: the action takes a reference, not an Order.
 */
final class ReserveFromHoldTest extends TestCase
{
    use RecordsLockOrder;
    use RefreshDatabase;

    #[Test]
    public function a_hold_that_covers_the_quantity_moves_it_all_and_stock_does_not_move(): void
    {
        [$hold, $bowl] = $this->holdOf(2, level: 5);
        $order = TestStockable::factory()->create();
        $levelBefore = $this->level($bowl);
        $ledgerBefore = $this->ledgerSum($bowl);

        $move = app(ReserveFromHold::class)($hold, $bowl, 2, $order, 'Moved from hold Markt to order 1');

        $this->assertSame(2, $move->fromHold);
        $this->assertSame(0, $move->shortfall);
        $this->assertSame(2, $move->reservation->quantity);
        $this->assertTrue($move->reservation->reference->is($order));
        $this->assertSame($hold->getKey(), $move->reservation->stock_hold_id, 'The order reservation records its hold (D10).');
        $this->assertSame(0, $hold->heldQuantity($bowl));
        $this->assertSame($levelBefore, $this->level($bowl), 'A move changes who holds the units, not how many can sell.');
        $this->assertSame($ledgerBefore, $this->ledgerSum($bowl), 'The release and the reserves cancel out in the ledger.');
        $this->assertSame(2, StockMovement::query()->where('description', 'Moved from hold Markt to order 1')->count(), 'The release and the reserve for the order, each described; nothing remains to re-reserve.');
    }

    #[Test]
    public function a_hold_short_of_the_quantity_moves_what_it_has_and_returns_the_shortfall(): void
    {
        [$hold, $bowl] = $this->holdOf(1, level: 5);

        $move = app(ReserveFromHold::class)($hold, $bowl, 2, TestStockable::factory()->create(), 'Moved');

        $this->assertSame(1, $move->fromHold);
        $this->assertSame(1, $move->shortfall);
        $this->assertSame(1, $move->reservation->quantity);
        $this->assertSame(0, $hold->heldQuantity($bowl));
    }

    #[Test]
    public function the_rest_stays_in_the_hold_and_neither_side_expires(): void
    {
        config(['inventory.reservation_ttl' => 30]);
        [$hold, $bowl] = $this->holdOf(3, level: 5);

        $move = app(ReserveFromHold::class)($hold, $bowl, 1, TestStockable::factory()->create(), 'Moved');

        $remainder = $hold->pendingReservations()->sole();
        $this->assertSame(2, $remainder->quantity);
        $this->assertNull($remainder->reserved_until);
        $this->assertNull($move->reservation->fresh()->reserved_until);

        $this->travel(2)->days();
        app(ReleaseExpiredReservations::class)();

        $this->assertSame(ReservationStatus::Pending, $remainder->fresh()->status);
        $this->assertSame(ReservationStatus::Pending, $move->reservation->fresh()->status);
    }

    #[Test]
    public function a_move_on_a_product_at_negative_stock_does_not_throw(): void
    {
        // Held when 1 was in stock, then backordered online to −2: the move
        // claims nothing new, so oversell is not re-checked.
        $madeToOrder = TestStockable::factory()->backorderable()->create();
        StockItem::factory()->for($madeToOrder, 'stockable')->withLevel(1)->create();
        $hold = StockHold::factory()->create();
        app(AdjustHold::class)($hold, $madeToOrder, 1);
        app(ReserveStock::class)($madeToOrder, 2, rejectOversell: false);
        $this->assertSame(-2, $this->level($madeToOrder));

        $move = app(ReserveFromHold::class)($hold, $madeToOrder, 1, TestStockable::factory()->create(), 'Moved');

        $this->assertSame(1, $move->fromHold);
        $this->assertSame(-2, $this->level($madeToOrder));
    }

    #[Test]
    public function a_hold_that_holds_none_of_the_product_moves_nothing(): void
    {
        [$hold] = $this->holdOf(2, level: 5);
        $cup = $this->stockable(5);
        $movements = StockMovement::query()->count();

        $move = app(ReserveFromHold::class)($hold, $cup, 2, TestStockable::factory()->create(), 'Moved');

        $this->assertNull($move->reservation);
        $this->assertSame(0, $move->fromHold);
        $this->assertSame(2, $move->shortfall);
        $this->assertSame($movements, StockMovement::query()->count());
    }

    #[Test]
    public function a_released_hold_is_refused_and_nothing_moves(): void
    {
        [$hold, $bowl] = $this->holdOf(2, level: 5);
        app(ReleaseHold::class)($hold);
        $movements = StockMovement::query()->count();

        try {
            app(ReserveFromHold::class)($hold, $bowl, 1, TestStockable::factory()->create(), 'Moved');
            $this->fail('A released hold must be refused.');
        } catch (StockHoldReleasedException) {
            // expected
        }

        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame(0, StockReservation::query()->where('status', ReservationStatus::Pending)->count());
    }

    #[Test]
    public function a_hold_on_one_edition_of_a_shared_inventory_group_serves_an_order_for_another(): void
    {
        $group = LocaleGroup::factory()->sharingInventory()->create();
        $en = TestStockableLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        $de = TestStockableLocalizable::factory()->create(['locale' => 'de', 'locale_group_id' => $group->id]);
        app(AdjustStock::class)($en, 4, StockMovementReason::Restock);
        $hold = StockHold::factory()->create();
        app(AdjustHold::class)($hold, $en, 2);

        $this->assertSame(2, $hold->heldQuantity($de), 'The hold is found from the other edition.');

        $move = app(ReserveFromHold::class)($hold, $de, 1, TestStockable::factory()->create(), 'Moved');

        $this->assertSame(1, $move->fromHold);
        $this->assertSame(1, $hold->heldQuantity($en));
        $this->assertSame(2, (int) $en->stockItem()->first()->stock_level);
        $this->assertSame(2, (int) $de->stockItem()->first()->stock_level);
    }

    #[Test]
    public function it_locks_the_hold_then_the_stock_item_then_the_holds_rows_and_never_reads_them_unlocked(): void
    {
        [$hold, $bowl] = $this->holdOf(3, level: 5);
        $order = TestStockable::factory()->create();

        $this->recordLockOrder();
        app(ReserveFromHold::class)($hold, $bowl, 1, $order, 'Moved');

        $this->assertLocksTakenInOrder(['stock_holds', 'stock_items', 'stock_reservations']);
    }

    /**
     * @return array{StockHold, TestStockable}
     */
    private function holdOf(int $held, int $level): array
    {
        $bowl = $this->stockable($level);
        $hold = StockHold::factory()->create(['name' => 'Markt']);
        app(AdjustHold::class)($hold, $bowl, $held);

        return [$hold, $bowl];
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

    private function ledgerSum(TestStockable $stockable): int
    {
        return (int) $stockable->stockItem()->first()->movements()->sum('quantity');
    }
}
