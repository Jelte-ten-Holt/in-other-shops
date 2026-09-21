<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Filament\Resources\OrderResource;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * The orders table's "Update status" action. It was the one way to confirm an
 * order that skipped ConfirmOrder's F14 check, so it confirmed zero
 * reservations when the stock had been released. The refusal now comes from
 * UpdateOrderStatus; this pins that the admin sees it as a notification, not
 * a 500, and that the order stays where it was.
 */
final class OrderResourceUpdateStatusActionTest extends TestCase
{
    use BootsFilament;
    use RefreshDatabase;

    #[Test]
    public function confirming_an_order_whose_stock_was_released_is_refused_with_a_reason(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $reservation = $this->reservationFor($order);
        $reservation->update(['status' => ReservationStatus::Released]);

        $this->updateStatusAction($order)->call(['data' => ['status' => OrderStatus::Confirmed->value]]);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(ReservationStatus::Released, $reservation->fresh()->status);
        Notification::assertNotified(__('shops-commerce::orders.notifications.status_refused'));
    }

    #[Test]
    public function confirming_an_order_whose_stock_is_held_goes_through(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $reservation = $this->reservationFor($order);

        $this->updateStatusAction($order)->call(['data' => ['status' => OrderStatus::Confirmed->value]]);

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        Notification::assertNotified(__('shops-commerce::orders.notifications.status_updated', [
            'status' => OrderStatus::Confirmed->label(),
        ]));
    }

    private function updateStatusAction(Order $order): Action
    {
        /** @var Action $action */
        $action = (new ReflectionMethod(OrderResource::class, 'updateStatusAction'))->invoke(null);

        return $action->record($order);
    }

    private function reservationFor(Order $order): \InOtherShops\Inventory\Models\StockReservation
    {
        $stockable = TestStockable::factory()->create();
        StockItem::factory()->for($stockable, 'stockable')->withLevel(10)->create();

        return (new ReserveStock(new AdjustStock))($stockable, quantity: 2, reference: $order);
    }
}
