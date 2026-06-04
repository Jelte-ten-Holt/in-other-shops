<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use Closure;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Filament\Resources\OrderResource\Pages\EditOrder;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;

/**
 * Wiring cover for the order-level refund actions. The refund flow itself is
 * covered end-to-end by RefundOrderTest; this pins that the two actions are
 * registered with the right names and that their visibility predicate matches
 * "has a refundable payment and isn't fully refunded yet".
 */
final class EditOrderRefundActionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function both_refund_actions_are_registered_with_expected_names(): void
    {
        $this->assertSame('partialRefund', EditOrder::partialRefundAction()->getName());
        $this->assertSame('refundAndCancel', EditOrder::refundAndCancelAction()->getName());
    }

    #[Test]
    public function refund_actions_are_visible_for_an_order_with_a_refundable_payment(): void
    {
        $order = $this->orderWithPayment(PaymentStatus::Succeeded);

        $this->assertTrue($this->visible(EditOrder::partialRefundAction(), $order));
        $this->assertTrue($this->visible(EditOrder::refundAndCancelAction(), $order));
    }

    #[Test]
    public function refund_actions_are_hidden_for_an_order_with_no_payment(): void
    {
        $order = Order::factory()->create(['total' => 1000]);

        $this->assertFalse($this->visible(EditOrder::partialRefundAction(), $order));
        $this->assertFalse($this->visible(EditOrder::refundAndCancelAction(), $order));
    }

    #[Test]
    public function refund_actions_are_hidden_once_the_order_is_fully_refunded(): void
    {
        $order = $this->orderWithPayment(PaymentStatus::Refunded);
        Refund::factory()->create(['order_id' => $order->id, 'amount' => 1000]);

        $this->assertFalse($this->visible(EditOrder::partialRefundAction(), $order->fresh()));
        $this->assertFalse($this->visible(EditOrder::refundAndCancelAction(), $order->fresh()));
    }

    #[Test]
    public function refund_and_cancel_is_hidden_for_an_already_cancelled_order(): void
    {
        $order = $this->orderWithPayment(PaymentStatus::PartiallyRefunded, OrderStatus::Cancelled);

        $this->assertFalse($this->visible(EditOrder::refundAndCancelAction(), $order));
    }

    private function orderWithPayment(PaymentStatus $status, OrderStatus $orderStatus = OrderStatus::Confirmed): Order
    {
        $order = Order::factory()->create(['status' => $orderStatus, 'total' => 1000]);

        Payment::factory()->for($order, 'payable')->create([
            'gateway' => 'fake',
            'amount' => 1000,
            'amount_refunded' => $status === PaymentStatus::PartiallyRefunded ? 200 : 0,
            'currency' => Currency::EUR,
            'status' => $status,
        ]);

        return $order;
    }

    private function visible(Action $action, Order $order): bool
    {
        $property = (new ReflectionObject($action))->getProperty('isVisible');
        $property->setAccessible(true);
        $value = $property->getValue($action);

        return $value instanceof Closure ? (bool) $value($order) : (bool) $value;
    }
}
