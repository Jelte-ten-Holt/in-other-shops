<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Exceptions\OrderFrozenException;
use InOtherShops\Commerce\Exceptions\OrderHoldsStockException;
use InOtherShops\Commerce\Order\Actions\UpdateOrderStatus;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Lines lock whenever an order holds stock (stock-holds brief D6, D13) — an
 * admin order's and an in-flight checkout's alike, because editing lines
 * under live reservations desyncs the two. The model refuses it, with its own
 * message: "frozen" would be wrong for an unpaid order. Deleting the order is
 * unaffected (its lines cascade at the database).
 */
final class OrderHoldsStockTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_order_holding_stock_refuses_a_changed_line(): void
    {
        [$order, $line] = $this->orderHoldingStock();

        $this->assertRefused(fn () => $line->update(['quantity' => 5]));
        $this->assertSame(1, $line->fresh()->quantity);
    }

    #[Test]
    public function an_order_holding_stock_refuses_a_new_line_and_a_removed_one(): void
    {
        [$order, $line] = $this->orderHoldingStock();

        $this->assertRefused(fn () => OrderLine::factory()->for($order)->create());
        $this->assertRefused(fn () => $line->delete());
        $this->assertSame(1, $order->lines()->count());
    }

    #[Test]
    public function a_cancelled_checkout_orders_lines_are_editable_again(): void
    {
        [$order, $line] = $this->orderHoldingStock();

        app(UpdateOrderStatus::class)($order, OrderStatus::Cancelled);
        $line->update(['quantity' => 2]);

        $this->assertFalse($order->holdsStock());
        $this->assertSame(2, $line->fresh()->quantity);
    }

    #[Test]
    public function an_order_holding_nothing_keeps_its_lines_editable(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $line = OrderLine::factory()->for($order)->create(['quantity' => 1]);

        $line->update(['quantity' => 3]);

        $this->assertFalse($order->holdsStock());
        $this->assertSame(3, $line->fresh()->quantity);
    }

    #[Test]
    public function a_paid_order_still_says_it_is_paid(): void
    {
        [$order, $line] = $this->orderHoldingStock();
        Payment::factory()->succeeded()->for($order, 'payable')->create(['amount' => $order->total]);

        $this->expectException(OrderFrozenException::class);

        $line->update(['quantity' => 5]);
    }

    /**
     * @return array{Order, OrderLine}
     */
    private function orderHoldingStock(): array
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending, 'order_number' => 'ORD-1']);
        $line = OrderLine::factory()->for($order)->create(['quantity' => 1]);
        $bowl = TestStockable::factory()->create();
        StockItem::factory()->for($bowl, 'stockable')->withLevel(5)->create();
        app(ReserveStock::class)($bowl, 1, reference: $order, source: 'checkout');

        return [$order, $line];
    }

    private function assertRefused(callable $change): void
    {
        try {
            $change();
            $this->fail('The change should have been refused.');
        } catch (OrderHoldsStockException $e) {
            $this->assertStringContainsString('ORD-1', $e->getMessage());
            $this->assertStringNotContainsString('paid', $e->getMessage());
        }
    }
}
