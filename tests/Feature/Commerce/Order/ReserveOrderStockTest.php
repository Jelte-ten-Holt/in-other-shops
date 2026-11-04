<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Commerce\Exceptions\StockHoldChangedException;
use InOtherShops\Commerce\Order\Actions\ConfirmOrder;
use InOtherShops\Commerce\Order\Actions\ReserveOrderStock;
use InOtherShops\Commerce\Order\Actions\UpdateOrderStatus;
use InOtherShops\Commerce\Order\Enums\ConfirmOrderOutcome;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Inventory\Actions\AdjustHold;
use InOtherShops\Inventory\Actions\ReleaseExpiredReservations;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Exceptions\InsufficientStockException;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Tests\Stubs\TestStockableOrderable;
use InOtherShops\Tests\Stubs\TestStockBundle;
use InOtherShops\Tests\Support\RecordsLockOrder;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * An admin order's stock step (stock-holds brief §4.3, D5, §8 Q2).
 */
final class ReserveOrderStockTest extends TestCase
{
    use RecordsLockOrder;
    use RefreshDatabase;

    #[Test]
    public function an_order_from_stock_reserves_its_lines_with_no_ttl_and_the_expiry_sweep_leaves_them(): void
    {
        config(['inventory.reservation_ttl' => 90]);
        $bowl = $this->product(5);
        $order = $this->orderWith([[$bowl, 2]]);

        app(ReserveOrderStock::class)($order);

        $reservation = StockReservation::query()->sole();
        $this->assertSame(2, $reservation->quantity);
        $this->assertNull($reservation->reserved_until);
        $this->assertNull($reservation->stock_hold_id);
        $this->assertSame('dashboard', $reservation->source);
        $this->assertSame('Admin order ORD-1', $reservation->description);
        $this->assertSame(3, $this->level($bowl));

        $this->travel(5)->days();
        app(ReleaseExpiredReservations::class)();

        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status);
    }

    #[Test]
    public function mark_paid_and_update_status_confirm_an_admin_orders_reservations(): void
    {
        $bowl = $this->product(5);
        $paid = $this->orderWith([[$bowl, 1]], 'ORD-1');
        $confirmed = $this->orderWith([[$bowl, 1]], 'ORD-2');
        app(ReserveOrderStock::class)($paid);
        app(ReserveOrderStock::class)($confirmed);

        $this->assertSame(ConfirmOrderOutcome::Confirmed, app(ConfirmOrder::class)($paid));
        app(UpdateOrderStatus::class)($confirmed, OrderStatus::Confirmed);

        $this->assertSame(0, StockReservation::query()->where('status', ReservationStatus::Pending)->count());
        $this->assertSame(2, StockReservation::query()->where('status', ReservationStatus::Confirmed)->count());
    }

    #[Test]
    public function a_hold_that_covers_the_line_supplies_all_of_it(): void
    {
        $bowl = $this->product(5);
        $hold = $this->hold([[$bowl, 2]]);
        $order = $this->orderWith([[$bowl, 2]]);

        app(ReserveOrderStock::class)($order, $hold);

        $reservation = StockReservation::query()->where('reference_type', 'order')->sole();
        $this->assertSame(2, $reservation->quantity);
        $this->assertSame($hold->getKey(), $reservation->stock_hold_id);
        $this->assertSame(0, $hold->heldQuantity($bowl));
        $this->assertSame(3, $this->level($bowl), 'From the hold: stock does not move.');
    }

    #[Test]
    public function a_line_the_hold_cannot_cover_takes_the_rest_from_main_stock(): void
    {
        // Hold 1, line 2 → 1 from the hold, 1 from stock (D5).
        $bowl = $this->product(5);
        $hold = $this->hold([[$bowl, 1]]);
        $order = $this->orderWith([[$bowl, 2]]);

        app(ReserveOrderStock::class)($order, $hold);

        $rows = StockReservation::query()->where('reference_type', 'order')->orderBy('id')->get();
        $this->assertSame([1, 1], $rows->pluck('quantity')->all());
        $this->assertSame([$hold->getKey(), null], $rows->pluck('stock_hold_id')->all());
        $this->assertSame(3, $this->level($bowl));
    }

    #[Test]
    public function a_shortfall_main_stock_cannot_cover_writes_nothing(): void
    {
        // Hold 1, line 2, stock 0 → refused; the record is wrong, so stock is
        // corrected first.
        $bowl = $this->product(1);
        $hold = $this->hold([[$bowl, 1]]);
        $order = $this->orderWith([[$bowl, 2]]);
        $movements = StockMovement::query()->count();

        try {
            DB::transaction(fn () => app(ReserveOrderStock::class)($order, $hold));
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame(0, StockReservation::query()->where('reference_type', 'order')->count());
        $this->assertSame(1, $hold->heldQuantity($bowl));
        $this->assertSame(0, $this->level($bowl));
    }

    #[Test]
    public function a_backorderable_product_at_zero_is_reserved_past_zero(): void
    {
        $madeToOrder = $this->product(0, backorderable: true);
        $order = $this->orderWith([[$madeToOrder, 1]]);

        app(ReserveOrderStock::class)($order);

        $this->assertSame(-1, $this->level($madeToOrder));
    }

    #[Test]
    public function a_bundle_line_takes_its_components_from_the_hold(): void
    {
        $bowl = $this->product(5);
        $cup = $this->product(5);
        $set = TestStockBundle::factory()->create(['components' => [
            ['type' => 'test_stockable_orderable', 'id' => $bowl->id, 'quantity' => 1],
            ['type' => 'test_stockable_orderable', 'id' => $cup->id, 'quantity' => 2],
        ]]);
        $hold = $this->hold([[$bowl, 1], [$cup, 2]]);
        $order = $this->orderWith([[$set, 1]]);

        app(ReserveOrderStock::class)($order, $hold);

        $this->assertSame(0, $hold->heldQuantity($bowl));
        $this->assertSame(0, $hold->heldQuantity($cup));
        $this->assertSame(3, (int) StockReservation::query()->where('reference_type', 'order')->sum('quantity'));
        $this->assertSame(4, $this->level($bowl));
        $this->assertSame(3, $this->level($cup));
    }

    #[Test]
    public function a_line_with_no_item_or_an_untracked_item_takes_nothing(): void
    {
        $download = TestStockableOrderable::factory()->untracked()->create();
        $order = Order::factory()->create(['status' => OrderStatus::Pending, 'order_number' => 'ORD-1']);
        OrderLine::factory()->for($order)->create(['orderable_type' => null, 'orderable_id' => null, 'description' => 'Packing', 'quantity' => 1]);
        OrderLine::factory()->for($order)->create(['orderable_type' => $download->getMorphClass(), 'orderable_id' => $download->id, 'quantity' => 1]);

        app(ReserveOrderStock::class)($order);

        $this->assertSame(0, StockReservation::query()->count());
    }

    #[Test]
    public function an_order_created_confirmed_has_its_reservations_confirmed_and_one_created_cancelled_takes_none(): void
    {
        $bowl = $this->product(5);
        $confirmed = $this->orderWith([[$bowl, 1]], 'ORD-1', OrderStatus::Confirmed);
        $cancelled = $this->orderWith([[$bowl, 1]], 'ORD-2', OrderStatus::Cancelled);

        app(ReserveOrderStock::class)($confirmed);
        app(ReserveOrderStock::class)($cancelled);

        $this->assertSame(ReservationStatus::Confirmed, StockReservation::query()->sole()->status);
        $this->assertSame(4, $this->level($bowl));
    }

    #[Test]
    public function a_hold_drained_since_the_page_showed_it_refuses_the_save_and_writes_nothing(): void
    {
        $bowl = $this->product(5);
        $hold = $this->hold([[$bowl, 2]]);
        $shown = $hold->heldByGroup();
        app(AdjustHold::class)($hold, $bowl, 1); // another admin's sale in between
        $order = $this->orderWith([[$bowl, 2]]);
        $movements = StockMovement::query()->count();

        try {
            DB::transaction(fn () => app(ReserveOrderStock::class)($order, $hold, $shown));
            $this->fail('Expected StockHoldChangedException.');
        } catch (StockHoldChangedException) {
            // expected
        }

        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame(1, $hold->heldQuantity($bowl));
    }

    #[Test]
    public function a_hold_topped_up_beyond_the_line_since_the_page_showed_it_saves_the_split_shown(): void
    {
        $bowl = $this->product(5);
        $hold = $this->hold([[$bowl, 2]]);
        $shown = $hold->heldByGroup();
        app(AdjustHold::class)($hold, $bowl, 3);
        $order = $this->orderWith([[$bowl, 2]]);

        app(ReserveOrderStock::class)($order, $hold, $shown);

        $this->assertSame(1, $hold->heldQuantity($bowl));
    }

    #[Test]
    public function it_locks_the_hold_then_every_stock_item_in_order_then_the_rows(): void
    {
        $bowl = $this->product(5);
        $cup = $this->product(5);
        $hold = $this->hold([[$bowl, 1], [$cup, 1]]);
        $order = $this->orderWith([[$cup, 1], [$bowl, 1]]);

        $this->recordLockOrder();
        app(ReserveOrderStock::class)($order, $hold);

        $this->assertLocksTakenInOrder(['stock_holds', 'stock_items', 'stock_reservations']);
    }

    private function product(int $level, bool $backorderable = false): TestStockableOrderable
    {
        $factory = TestStockableOrderable::factory();
        $product = ($backorderable ? $factory->backorderable() : $factory)->create();
        StockItem::factory()->for($product, 'stockable')->withLevel($level)->create();

        return $product;
    }

    /**
     * @param  list<array{\Illuminate\Database\Eloquent\Model, int}>  $lines
     */
    private function orderWith(array $lines, string $number = 'ORD-1', OrderStatus $status = OrderStatus::Pending): Order
    {
        $order = Order::factory()->create(['status' => $status, 'order_number' => $number]);

        foreach ($lines as [$orderable, $quantity]) {
            OrderLine::factory()->for($order)->create([
                'orderable_type' => $orderable->getMorphClass(),
                'orderable_id' => $orderable->getKey(),
                'quantity' => $quantity,
            ]);
        }

        return $order;
    }

    /**
     * @param  list<array{TestStockableOrderable, int}>  $holding
     */
    private function hold(array $holding): StockHold
    {
        $hold = StockHold::factory()->create(['name' => 'Markt']);

        foreach ($holding as [$product, $quantity]) {
            app(AdjustHold::class)($hold, $product, $quantity);
        }

        return $hold;
    }

    private function level(TestStockableOrderable $product): int
    {
        return (int) $product->stockItem()->first()->stock_level;
    }
}
