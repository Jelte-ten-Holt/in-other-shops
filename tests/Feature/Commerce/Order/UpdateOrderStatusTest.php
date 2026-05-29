<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Commerce\Exceptions\InvalidOrderStatusTransitionException;
use InOtherShops\Commerce\Order\Actions\UpdateOrderStatus;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Events\OrderStatusChanged;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Pins the transactional contract of UpdateOrderStatus (audit C-1): the status
 * write, the OrderStatusChanged event, and the synchronous listeners it drives
 * all commit or roll back together. Without it a listener failure would leave
 * the order in a terminal status with its inventory side-effects half-applied
 * and no retry path — the exact end-state v0.23.0 shipped to fix.
 */
final class UpdateOrderStatusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_the_new_status_and_dispatches_the_event_on_a_valid_transition(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $order = $this->orderWithStatus(OrderStatus::Pending);

        (new UpdateOrderStatus)($order, OrderStatus::Confirmed);

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        Event::assertDispatched(
            OrderStatusChanged::class,
            fn (OrderStatusChanged $event) => $event->order->is($order)
                && $event->from === OrderStatus::Pending
                && $event->to === OrderStatus::Confirmed,
        );
    }

    #[Test]
    public function it_rejects_an_invalid_transition_without_writing_or_dispatching(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $order = $this->orderWithStatus(OrderStatus::Cancelled);

        $this->expectException(InvalidOrderStatusTransitionException::class);

        try {
            (new UpdateOrderStatus)($order, OrderStatus::Confirmed);
        } finally {
            $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
            Event::assertNotDispatched(OrderStatusChanged::class);
        }
    }

    #[Test]
    public function a_listener_failure_rolls_back_the_status_change_and_its_inventory_side_effects(): void
    {
        // The C-1 scenario: a downstream OrderStatusChanged listener throws
        // after the package's inventory listener has already released stock.
        // Everything must roll back to Pending — including the reservation
        // release — so the cancellation can be retried, instead of stranding
        // the order in Cancelled with stock already returned.
        $stockable = $this->stockableWithLevel(10);
        $order = $this->orderWithStatus(OrderStatus::Pending);

        $adjust = new AdjustStock;
        $reservation = (new ReserveStock($adjust))($stockable, quantity: 4, reference: $order);
        $this->assertSame(6, $stockable->stockItem()->first()->fresh()->stock_level);

        Event::listen(OrderStatusChanged::class, function (): void {
            throw new RuntimeException('downstream listener blew up');
        });

        try {
            (new UpdateOrderStatus)($order, OrderStatus::Cancelled);
            $this->fail('Expected the listener exception to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status,
            'Status must roll back when a listener throws.');
        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status,
            'The inventory release must roll back with the status change.');
        $this->assertSame(6, $stockable->stockItem()->first()->fresh()->stock_level,
            'Released stock must not be returned to available on a rolled-back cancellation.');
    }

    private function stockableWithLevel(int $level): TestStockable
    {
        $stockable = TestStockable::factory()->create();

        StockItem::factory()
            ->for($stockable, 'stockable')
            ->withLevel($level)
            ->create();

        return $stockable;
    }

    private function orderWithStatus(OrderStatus $status): Order
    {
        return Order::create([
            'order_number' => 'TEST-'.uniqid('', true),
            'status' => $status,
            'currency' => 'EUR',
        ]);
    }
}
