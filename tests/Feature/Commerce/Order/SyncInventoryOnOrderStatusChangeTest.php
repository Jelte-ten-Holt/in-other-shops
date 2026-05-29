<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Actions\UpdateOrderStatus;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Events\OrderStatusChanged;
use InOtherShops\Commerce\Order\Listeners\SyncInventoryOnOrderStatusChange;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ConfirmReservation;
use InOtherShops\Inventory\Actions\ReleaseReservation;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Listener that closes the gap where any path mutating order status —
 * payment webhook, admin Filament action, future refund flows — would
 * otherwise leave inventory dangling. Tests cover all three transitions
 * the OrderStatus enum allows plus idempotency, since this listener runs
 * alongside consumer payment listeners that also call ConfirmReservation /
 * ReleaseReservation explicitly.
 */
final class SyncInventoryOnOrderStatusChangeTest extends TestCase
{
    use RefreshDatabase;

    private ReserveStock $reserve;

    private SyncInventoryOnOrderStatusChange $listener;

    private UpdateOrderStatus $updateOrderStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $adjust = new AdjustStock;
        $this->reserve = new ReserveStock($adjust);
        $this->listener = new SyncInventoryOnOrderStatusChange(
            new ConfirmReservation,
            new ReleaseReservation($adjust),
        );
        $this->updateOrderStatus = new UpdateOrderStatus;
    }

    #[Test]
    public function it_confirms_pending_reservations_on_pending_to_confirmed(): void
    {
        $stockable = $this->stockableWithLevel(10);
        $order = $this->orderWithStatus(OrderStatus::Pending);

        $reservation = ($this->reserve)($stockable, quantity: 3, reference: $order);
        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status);

        $this->listener->handle(new OrderStatusChanged($order, OrderStatus::Pending, OrderStatus::Confirmed));

        $this->assertSame(
            ReservationStatus::Confirmed,
            $reservation->fresh()->status,
            'Pending → Confirmed must flip the reservation lifecycle marker to Confirmed.',
        );
        $this->assertSame(7, $stockable->stockItem()->first()->fresh()->stock_level,
            'Confirming does not touch stock — reservation already decremented at reserve-time.');
    }

    #[Test]
    public function it_releases_pending_reservations_on_pending_to_cancelled(): void
    {
        $stockable = $this->stockableWithLevel(10);
        $order = $this->orderWithStatus(OrderStatus::Pending);

        $reservation = ($this->reserve)($stockable, quantity: 4, reference: $order);
        $this->assertSame(6, $stockable->stockItem()->first()->fresh()->stock_level);

        $this->listener->handle(new OrderStatusChanged($order, OrderStatus::Pending, OrderStatus::Cancelled));

        $this->assertSame(ReservationStatus::Released, $reservation->fresh()->status);
        $this->assertSame(10, $stockable->stockItem()->first()->fresh()->stock_level,
            'Cancelling a Pending order must return reserved stock to available.');
    }

    #[Test]
    public function it_releases_confirmed_reservations_on_confirmed_to_cancelled(): void
    {
        // The case that bites in real shops: admin cancels an order whose
        // payment already succeeded (reservation = Confirmed). Before the
        // listener + ReleaseReservation broadening, stock stayed locked to
        // the cancelled order forever.
        $stockable = $this->stockableWithLevel(10);
        $order = $this->orderWithStatus(OrderStatus::Confirmed);

        $reservation = ($this->reserve)($stockable, quantity: 2, reference: $order);
        $reservation->update(['status' => ReservationStatus::Confirmed]);

        $this->assertSame(8, $stockable->stockItem()->first()->fresh()->stock_level);

        $this->listener->handle(new OrderStatusChanged($order, OrderStatus::Confirmed, OrderStatus::Cancelled));

        $this->assertSame(ReservationStatus::Released, $reservation->fresh()->status,
            'Confirmed → Cancelled must release the previously-committed reservation.');
        $this->assertSame(10, $stockable->stockItem()->first()->fresh()->stock_level,
            'Releasing a Confirmed reservation must return the held stock to available.');
    }

    #[Test]
    public function it_is_idempotent_when_a_consumer_listener_already_did_the_work(): void
    {
        // Mirrors the real production scenario: an in-other-worlds-style
        // HandlePaymentSucceeded calls ConfirmReservation explicitly, then
        // UpdateOrderStatus, which dispatches OrderStatusChanged, which
        // this listener picks up and tries to ConfirmReservation again.
        // The lockForUpdate + status guard inside ConfirmReservation makes
        // the second call a no-op rather than an error or double-decrement.
        $stockable = $this->stockableWithLevel(10);
        $order = $this->orderWithStatus(OrderStatus::Pending);

        $reservation = ($this->reserve)($stockable, quantity: 5, reference: $order);

        (new ConfirmReservation)($order, 'Consumer payment listener already ran');
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);

        // Now the package listener fires on the OrderStatusChanged that
        // UpdateOrderStatus dispatched. Must not error or double-process.
        $this->listener->handle(new OrderStatusChanged($order, OrderStatus::Pending, OrderStatus::Confirmed));

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status,
            'Second confirm finds nothing pending — already-confirmed reservations stay Confirmed.');
        $this->assertSame(5, $stockable->stockItem()->first()->fresh()->stock_level,
            'Stock level must not change on a redundant confirm.');
    }

    #[Test]
    public function it_fires_automatically_when_update_order_status_dispatches_the_event(): void
    {
        // End-to-end: the listener is wired by CommerceServiceProvider, so
        // calling UpdateOrderStatus alone (no manual event dispatch, no
        // payment listener) must close the inventory loop. This is the
        // shape of the Filament admin "Update Status" button path.
        $stockable = $this->stockableWithLevel(10);
        $order = $this->orderWithStatus(OrderStatus::Pending);

        $reservation = ($this->reserve)($stockable, quantity: 3, reference: $order);

        ($this->updateOrderStatus)($order, OrderStatus::Confirmed);

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status,
            'The package-shipped listener must run via UpdateOrderStatus → OrderStatusChanged, '
            .'without any consumer-side wiring.');
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
