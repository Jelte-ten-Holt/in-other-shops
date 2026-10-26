<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use Closure;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Filament\Resources\OrderResource\Pages\EditOrder;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;

/**
 * Wiring cover for "Mark paid" on the order edit page, and for the refund
 * actions staying away from a manual payment. The behaviour is
 * RecordManualPaymentTest's.
 */
final class EditOrderMarkPaidActionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function mark_paid_is_offered_on_an_unpaid_order_and_withdrawn_once_it_is_paid(): void
    {
        $order = Order::factory()->create(['total' => 9590]);
        $this->assertSame('markPaid', EditOrder::markPaidAction()->getName());
        $this->assertTrue($this->visible(EditOrder::markPaidAction(), $order));

        Payment::factory()->succeeded()->for($order, 'payable')->create(['amount' => 9590, 'gateway' => 'manual']);

        $this->assertFalse($this->visible(EditOrder::markPaidAction(), $order->fresh()));
    }

    #[Test]
    public function the_refund_actions_are_not_offered_for_a_manual_payment(): void
    {
        $order = Order::factory()->create(['total' => 9590]);
        Payment::factory()->succeeded()->for($order, 'payable')->create(['amount' => 9590, 'gateway' => 'manual']);

        $this->assertFalse($this->visible(EditOrder::partialRefundAction(), $order));
        $this->assertFalse($this->visible(EditOrder::refundAndCancelAction(), $order));
    }

    private function visible(Action $action, Order $order): bool
    {
        $property = (new ReflectionObject($action))->getProperty('isVisible');
        $value = $property->getValue($action);

        return $value instanceof Closure ? (bool) $value($order) : (bool) $value;
    }
}
