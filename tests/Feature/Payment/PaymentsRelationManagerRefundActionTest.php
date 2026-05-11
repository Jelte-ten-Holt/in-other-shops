<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment;

use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Filament\RelationManagers\PaymentsRelationManager;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * Pins the refund-action wiring on the PaymentsRelationManager. The action
 * lives in the Payment domain because it operates on a Payment model and
 * dispatches `PaymentRefunded`; this regression cover means a future
 * contributor can't quietly unregister it from the table's row actions, and
 * the visibility predicate stays aligned with `RefundPayment`'s own
 * "refundable" rules (Succeeded + PartiallyRefunded, balance > 0).
 *
 * Full end-to-end behaviour is covered by `RefundPaymentTest` against the
 * underlying action; this file only verifies the Filament glue.
 */
final class PaymentsRelationManagerRefundActionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function refund_action_is_registered_on_the_relation_manager(): void
    {
        $action = $this->refundAction();

        $this->assertInstanceOf(Action::class, $action);
        $this->assertSame('refund', $action->getName());
    }

    #[Test]
    public function refund_action_is_visible_for_a_succeeded_payment_with_remaining_balance(): void
    {
        $payment = $this->paymentWith(status: PaymentStatus::Succeeded, refunded: 0);

        $this->assertTrue($this->invokeVisible($payment));
    }

    #[Test]
    public function refund_action_is_visible_for_a_partially_refunded_payment_with_remaining_balance(): void
    {
        $payment = $this->paymentWith(status: PaymentStatus::PartiallyRefunded, refunded: 1000);

        $this->assertTrue($this->invokeVisible($payment));
    }

    #[Test]
    public function refund_action_is_hidden_for_a_fully_refunded_payment(): void
    {
        $payment = $this->paymentWith(status: PaymentStatus::Refunded, refunded: 2500);

        $this->assertFalse($this->invokeVisible($payment));
    }

    #[Test]
    public function refund_action_is_hidden_for_a_pending_payment(): void
    {
        $payment = $this->paymentWith(status: PaymentStatus::Pending, refunded: 0);

        $this->assertFalse($this->invokeVisible($payment));
    }

    #[Test]
    public function refund_action_is_hidden_when_amount_refunded_equals_amount_even_if_status_is_succeeded(): void
    {
        // Defensive case: should never happen in practice (RefundPayment
        // transitions Succeeded→Refunded once balance is consumed), but the
        // visibility predicate must not assume that invariant holds.
        $payment = $this->paymentWith(status: PaymentStatus::Succeeded, refunded: 2500);

        $this->assertFalse($this->invokeVisible($payment));
    }

    private function refundAction(): Action
    {
        $method = new ReflectionMethod(PaymentsRelationManager::class, 'refundAction');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    private function invokeVisible(Payment $payment): bool
    {
        // Reach into Filament's protected $isVisible closure and invoke it
        // directly with a Payment. The public Action::isVisible() would also
        // work but needs a full Livewire/Filament context to resolve the
        // record binding — reflecting the closure avoids the boot cost.
        $action = $this->refundAction();
        $reflection = new \ReflectionObject($action);
        $property = $reflection->getProperty('isVisible');
        $property->setAccessible(true);
        $value = $property->getValue($action);

        if (! $value instanceof \Closure) {
            return (bool) $value;
        }

        return (bool) $value($payment);
    }

    private function paymentWith(PaymentStatus $status, int $refunded): Payment
    {
        $payable = TestPayable::factory()->create(['total_due' => 2500]);

        return Payment::factory()->for($payable, 'payable')->create([
            'amount' => 2500,
            'amount_refunded' => $refunded,
            'currency' => Currency::EUR,
            'status' => $status,
        ]);
    }
}
