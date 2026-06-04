<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Commerce\Order\Actions\ConfirmOrder;
use InOtherShops\Commerce\Order\Enums\ConfirmOrderOutcome;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Events\OrderConfirmationBlocked;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Confirming a paid order exactly once, with the stock guard F14 lacked. The
 * outcome drives whether the caller fires buyer-facing side effects, so each
 * branch must be exact: confirm once, no-op on redelivery, flag (never silently
 * confirm) when the held stock is gone or the order is no longer confirmable.
 */
final class ConfirmOrderTest extends TestCase
{
    use RefreshDatabase;

    private ConfirmOrder $confirm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirm = $this->app->make(ConfirmOrder::class);
    }

    #[Test]
    public function it_confirms_a_pending_order_whose_stock_is_still_held(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $reservation = $this->reservationFor($order, 2);

        $outcome = ($this->confirm)($order);

        $this->assertSame(ConfirmOrderOutcome::Confirmed, $outcome);
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    #[Test]
    public function an_already_confirmed_order_is_a_noop_and_fires_no_blocked_event(): void
    {
        Event::fake([OrderConfirmationBlocked::class]);
        $order = Order::factory()->create(['status' => OrderStatus::Confirmed]);

        $outcome = ($this->confirm)($order);

        $this->assertSame(ConfirmOrderOutcome::AlreadyConfirmed, $outcome);
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        Event::assertNotDispatched(OrderConfirmationBlocked::class);
    }

    #[Test]
    public function it_refuses_to_confirm_when_the_reservations_were_released(): void
    {
        Event::fake([OrderConfirmationBlocked::class]);
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $reservation = $this->reservationFor($order, 2);
        // Simulate the expiry cron having released the held stock (F14).
        $reservation->update(['status' => ReservationStatus::Released]);

        $outcome = ($this->confirm)($order);

        $this->assertSame(ConfirmOrderOutcome::StockUnavailable, $outcome);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status,
            'A paid order with no held stock must NOT advance to Confirmed.');
        Event::assertDispatched(OrderConfirmationBlocked::class,
            fn (OrderConfirmationBlocked $e): bool => $e->order->is($order));
    }

    #[Test]
    public function it_flags_a_paid_order_that_is_no_longer_confirmable(): void
    {
        Event::fake([OrderConfirmationBlocked::class]);
        $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);

        $outcome = ($this->confirm)($order);

        $this->assertSame(ConfirmOrderOutcome::NotConfirmable, $outcome);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        Event::assertDispatched(OrderConfirmationBlocked::class);
    }

    #[Test]
    public function an_order_with_no_reservations_at_all_confirms(): void
    {
        // No reservations means nothing was ever held (e.g. digital goods) —
        // not the F14 case, so confirmation proceeds.
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);

        $outcome = ($this->confirm)($order);

        $this->assertSame(ConfirmOrderOutcome::Confirmed, $outcome);
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    private function reservationFor(Order $order, int $quantity): StockReservation
    {
        $stockable = TestStockable::factory()->create();
        StockItem::factory()->for($stockable, 'stockable')->withLevel(50)->create();

        return (new ReserveStock(new AdjustStock))(
            $stockable,
            quantity: $quantity,
            reference: $order,
            source: 'checkout',
        );
    }
}
