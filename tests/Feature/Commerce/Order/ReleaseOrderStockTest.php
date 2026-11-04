<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Actions\ReleaseOrderStock;
use InOtherShops\Commerce\Order\Actions\UpdateOrderStatus;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Inventory\Actions\AdjustHold;
use InOtherShops\Inventory\Actions\ReleaseHold;
use InOtherShops\Inventory\Actions\ReserveFromHold;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\Support\RecordsLockOrder;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Units an order took from a hold go back to that hold when the order is
 * cancelled or deleted, while the hold is open (stock-holds brief D10).
 */
final class ReleaseOrderStockTest extends TestCase
{
    use RecordsLockOrder;
    use RefreshDatabase;

    #[Test]
    public function cancelling_an_order_that_took_from_an_open_hold_puts_the_units_back_in_the_hold(): void
    {
        [$order, $hold, $bowl] = $this->orderFromHold();

        app(UpdateOrderStatus::class)($order, OrderStatus::Cancelled);

        $this->assertSame(2, $hold->heldQuantity($bowl), 'Back in the hold, not on sale online.');
        $this->assertSame(3, $this->level($bowl));
        $this->assertFalse($order->holdsStock());
        $back = $hold->pendingReservations()->latest('id')->first();
        $this->assertNull($back->reserved_until);
        $this->assertSame('Returned from order ORD-7 to hold Markt', $back->reserveMovement->description);
    }

    #[Test]
    public function deleting_an_unpaid_order_that_took_from_an_open_hold_puts_the_units_back_in_the_hold(): void
    {
        [$order, $hold, $bowl] = $this->orderFromHold();

        $order->delete();

        $this->assertSame(2, $hold->heldQuantity($bowl));
        $this->assertSame(3, $this->level($bowl));
        $release = StockMovement::query()->where('reason', StockMovementReason::Released)->latest('id')->first();
        $this->assertSame('order', $release->reference_type, 'The release movement still names the order, though its row is gone.');
        $this->assertSame($order->getKey(), (int) $release->reference_id);
    }

    #[Test]
    public function units_from_a_released_hold_go_back_to_main_stock(): void
    {
        [$order, $hold, $bowl] = $this->orderFromHold();
        app(ReleaseHold::class)($hold);

        app(UpdateOrderStatus::class)($order, OrderStatus::Cancelled);

        $this->assertSame(0, $hold->pendingReservations()->count());
        $this->assertSame(5, $this->level($bowl), 'On sale again: the hold it came from is over.');
    }

    #[Test]
    public function a_hold_edited_down_to_none_of_the_product_still_gets_its_units_back_while_open(): void
    {
        [$order, $hold, $bowl] = $this->orderFromHold();
        app(AdjustHold::class)($hold, $bowl, 0);

        $order->delete();

        $this->assertSame(2, $hold->heldQuantity($bowl));
    }

    #[Test]
    public function deleting_an_unpaid_order_that_took_from_main_stock_releases_it(): void
    {
        $bowl = $this->stockable(5);
        $order = Order::factory()->create(['status' => OrderStatus::Pending, 'order_number' => 'ORD-8']);
        app(ReserveStock::class)($bowl, 2, reference: $order, source: 'dashboard', expires: false);

        $order->delete();

        $this->assertSame(5, $this->level($bowl));
        $this->assertSame(0, StockReservation::query()->where('status', ReservationStatus::Pending)->count());
        $this->assertSame('Order ORD-8 deleted', StockMovement::query()->where('reason', StockMovementReason::Released)->sole()->description);
    }

    #[Test]
    public function it_locks_the_hold_then_the_stock_items_then_the_orders_rows(): void
    {
        [$order] = $this->orderFromHold();

        $this->recordLockOrder();
        app(ReleaseOrderStock::class)($order);

        $this->assertLocksTakenInOrder(['stock_holds', 'stock_items', 'stock_reservations']);
    }

    #[Test]
    public function an_order_of_two_products_locks_both_stock_items_before_any_row(): void
    {
        $bowl = $this->stockable(5);
        $cup = $this->stockable(5);
        $hold = StockHold::factory()->create(['name' => 'Markt']);
        app(AdjustHold::class)($hold, $bowl, 1);
        app(AdjustHold::class)($hold, $cup, 1);
        $order = Order::factory()->create(['status' => OrderStatus::Pending, 'order_number' => 'ORD-9']);
        app(ReserveFromHold::class)($hold, $cup, 1, $order, 'Moved');
        app(ReserveFromHold::class)($hold, $bowl, 1, $order, 'Moved');

        $this->recordLockOrder();
        app(ReleaseOrderStock::class)($order);

        $this->assertLocksTakenInOrder(['stock_holds', 'stock_items', 'stock_reservations']);
        $this->assertSame(1, $hold->heldQuantity($bowl));
        $this->assertSame(1, $hold->heldQuantity($cup));
    }

    /**
     * An order for 2 bowls, both from "Markt", which held 2 of 5.
     *
     * @return array{Order, StockHold, TestStockable}
     */
    private function orderFromHold(): array
    {
        $bowl = $this->stockable(5);
        $hold = StockHold::factory()->create(['name' => 'Markt']);
        app(AdjustHold::class)($hold, $bowl, 2);
        $order = Order::factory()->create(['status' => OrderStatus::Pending, 'order_number' => 'ORD-7']);
        app(ReserveFromHold::class)($hold, $bowl, 2, $order, 'Moved from hold Markt to order ORD-7');

        return [$order, $hold, $bowl];
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
