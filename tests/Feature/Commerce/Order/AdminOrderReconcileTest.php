<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Commerce\Order\Actions\ReserveOrderStock;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ReconcileStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Tests\Stubs\TestStockableOrderable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * An unpaid admin order holds stock with no TTL on purpose; reconcile asks the
 * order, through HasOpenReservations (stock-holds brief §4.5, D15).
 */
final class AdminOrderReconcileTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_unpaid_admin_orders_reservations_are_not_errors(): void
    {
        $this->adminOrderHoldingStock();

        $report = app(ReconcileStock::class)();

        $this->assertTrue($report->isClean());
        $this->assertSame([], $report->staleReferences);
    }

    #[Test]
    public function one_holding_stock_past_the_threshold_is_listed_as_a_warning(): void
    {
        $this->travelTo('2026-11-01 10:00:00');
        $order = $this->adminOrderHoldingStock();
        $this->travelTo('2026-11-09 10:00:01');

        $report = app(ReconcileStock::class)();

        $this->assertTrue($report->isClean());
        $this->assertSame([['type' => 'order', 'id' => $order->id]], $report->staleReferences);
        $this->artisan('inventory:reconcile')->expectsOutputToContain("order#{$order->id}")->assertExitCode(0);
    }

    #[Test]
    public function the_stock_of_an_order_deleted_without_releasing_is_still_an_error(): void
    {
        // The non-Filament path: a delete that skips the model hook leaves
        // the reservations on a reference that no longer exists.
        $order = $this->adminOrderHoldingStock();
        DB::table('order_lines')->where('order_id', $order->id)->delete();
        DB::table('orders')->where('id', $order->id)->delete();

        $report = app(ReconcileStock::class)();

        $this->assertFalse($report->isClean());
        $this->assertSame(StockReservation::query()->pluck('id')->all(), $report->nullTtlPendingReservationIds);
    }

    private function adminOrderHoldingStock(): Order
    {
        $bowl = TestStockableOrderable::factory()->create();
        app(AdjustStock::class)($bowl, 5, StockMovementReason::Received);
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        OrderLine::factory()->for($order)->create(['orderable_type' => $bowl->getMorphClass(), 'orderable_id' => $bowl->id, 'quantity' => 1]);
        app(ReserveOrderStock::class)($order);

        return $order;
    }
}
