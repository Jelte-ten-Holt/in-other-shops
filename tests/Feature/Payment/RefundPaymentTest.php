<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Actions\RefundPayment;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Events\PaymentRefunded;
use InOtherShops\Payment\Exceptions\PaymentNotRefundableException;
use InOtherShops\Payment\Exceptions\RefundAmountExceededException;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Payment\Testing\FakePaymentGateway;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class RefundPaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private RefundPayment $refund;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway('fake');

        $manager = $this->app->make(PaymentGatewayManager::class);
        $manager->extend('fake', fn (): FakePaymentGateway => $this->gateway);

        $this->refund = $this->app->make(RefundPayment::class);
    }

    #[Test]
    public function a_full_refund_with_no_amount_argument_marks_the_payment_refunded(): void
    {
        $payment = $this->successfulPayment(2500);

        ($this->refund)($payment);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(2500, $payment->amount_refunded);
    }

    #[Test]
    public function a_partial_refund_marks_the_payment_partially_refunded(): void
    {
        $payment = $this->successfulPayment(2500);

        ($this->refund)($payment, 1000);

        $payment->refresh();
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        $this->assertSame(1000, $payment->amount_refunded);
    }

    #[Test]
    public function multiple_partial_refunds_summing_to_the_full_amount_mark_the_payment_refunded(): void
    {
        $payment = $this->successfulPayment(2500);

        ($this->refund)($payment, 1000);
        ($this->refund)($payment, 1500);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(2500, $payment->amount_refunded);
    }

    #[Test]
    public function a_partial_refund_after_an_initial_partial_refund_keeps_partially_refunded(): void
    {
        $payment = $this->successfulPayment(2500);

        ($this->refund)($payment, 500);
        ($this->refund)($payment, 500);

        $payment->refresh();
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        $this->assertSame(1000, $payment->amount_refunded);
    }

    #[Test]
    public function refunding_a_pending_payment_throws_and_does_not_mutate(): void
    {
        $payment = $this->pendingPayment(2500);

        try {
            ($this->refund)($payment);
            $this->fail('Expected PaymentNotRefundableException.');
        } catch (PaymentNotRefundableException) {
            // expected
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(0, $payment->amount_refunded);
        $this->assertCount(0, $this->gateway->recordedRefunds(),
            'Gateway must not be called for non-refundable payments.');
    }

    #[Test]
    public function refunding_a_failed_payment_throws_and_does_not_mutate(): void
    {
        Event::fake([PaymentRefunded::class]);

        $payment = $this->paymentWithStatus(2500, PaymentStatus::Failed);

        try {
            ($this->refund)($payment);
            $this->fail('Expected PaymentNotRefundableException.');
        } catch (PaymentNotRefundableException) {
            // expected
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame(0, $payment->amount_refunded);
        $this->assertCount(0, $this->gateway->recordedRefunds(),
            'Gateway must not be called for non-refundable payments.');
        Event::assertNotDispatched(PaymentRefunded::class);
    }

    #[Test]
    public function refunding_an_already_fully_refunded_payment_throws_and_does_not_mutate(): void
    {
        Event::fake([PaymentRefunded::class]);

        $payment = $this->paymentWithStatus(2500, PaymentStatus::Refunded);
        $payment->update(['amount_refunded' => 2500]);

        try {
            ($this->refund)($payment);
            $this->fail('Expected PaymentNotRefundableException.');
        } catch (PaymentNotRefundableException) {
            // expected
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(2500, $payment->amount_refunded,
            'amount_refunded must not advance past the original full-refund value.');
        $this->assertCount(0, $this->gateway->recordedRefunds());
        Event::assertNotDispatched(PaymentRefunded::class);
    }

    #[Test]
    public function refunding_more_than_remaining_refundable_throws_and_does_not_mutate(): void
    {
        $payment = $this->successfulPayment(2500);
        ($this->refund)($payment, 1500);

        try {
            ($this->refund)($payment, 1500); // 1000 left
            $this->fail('Expected RefundAmountExceededException.');
        } catch (RefundAmountExceededException) {
            // expected
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status,
            'Status must not flip to Refunded when the second call throws.');
        $this->assertSame(1500, $payment->amount_refunded,
            'amount_refunded must not advance when the second call throws.');
    }

    #[Test]
    public function refunding_a_zero_amount_throws_invalid_argument_and_does_not_mutate(): void
    {
        Event::fake([PaymentRefunded::class]);

        $payment = $this->successfulPayment(2500);

        try {
            ($this->refund)($payment, 0);
            $this->fail('Expected InvalidArgumentException for zero amount.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(0, $payment->amount_refunded);
        $this->assertCount(0, $this->gateway->recordedRefunds());
        Event::assertNotDispatched(PaymentRefunded::class);
    }

    #[Test]
    public function refunding_a_negative_amount_throws_invalid_argument_and_does_not_mutate(): void
    {
        Event::fake([PaymentRefunded::class]);

        $payment = $this->successfulPayment(2500);

        try {
            ($this->refund)($payment, -100);
            $this->fail('Expected InvalidArgumentException for negative amount.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(0, $payment->amount_refunded);
        $this->assertCount(0, $this->gateway->recordedRefunds());
        Event::assertNotDispatched(PaymentRefunded::class);
    }

    #[Test]
    public function it_returns_a_refund_result_with_the_gateway_id_and_cumulative(): void
    {
        $payment = $this->successfulPayment(2500);

        $result = ($this->refund)($payment, 1000);

        // The gateway refund id is the anchor Commerce records the Refund row
        // against; RefundPayment itself records nothing and dispatches nothing.
        $this->assertSame($this->gateway->recordedRefunds()[0]['id'], $result->gatewayRefundId);
        $this->assertSame(1000, $result->amount);
        $this->assertSame(1000, $result->cumulativeRefunded);

        $second = ($this->refund)($payment, 1500);
        $this->assertSame(2500, $second->cumulativeRefunded);
        $this->assertNotSame($result->gatewayRefundId, $second->gatewayRefundId);
    }

    #[Test]
    public function it_calls_the_gateway_refund_with_the_resolved_amount(): void
    {
        $payment = $this->successfulPayment(2500);

        ($this->refund)($payment, 800);

        $recorded = $this->gateway->recordedRefunds();
        $this->assertCount(1, $recorded);
        $this->assertSame($payment->id, $recorded[0]['payment']->id);
        $this->assertSame(800, $recorded[0]['amount']);
    }

    #[Test]
    public function a_re_refund_after_a_lost_local_write_is_rejected_by_the_gateway_cap(): void
    {
        // F34 residue: the gateway refund succeeded but the amount_refunded write
        // was lost (transaction rolled back). The admin re-clicks; the local row
        // still reads 0 so RefundPayment's own cap allows the call — but the
        // gateway enforces its OWN cap from its records and rejects it, so the
        // money cannot be refunded twice. This is the money-safety backstop that
        // makes accepting the F34 window safe.
        $payment = $this->successfulPayment(2500);

        // The first refund lands at the gateway...
        $this->gateway->refund($payment, 2500);
        // ...but the local row never recorded it (the partial-failure state).
        $payment->refresh();
        $this->assertSame(0, $payment->amount_refunded);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);

        try {
            ($this->refund)($payment); // re-click: local cap passes, gateway rejects
            $this->fail('Expected the gateway to reject a refund it has already fully issued.');
        } catch (RefundAmountExceededException) {
            // expected
        }

        $this->assertCount(1, $this->gateway->recordedRefunds(),
            'the gateway must not issue a second refund for money it already returned');
        $this->assertSame(0, $payment->refresh()->amount_refunded,
            'the rejected re-click leaves the local row untouched');
    }

    private function successfulPayment(int $amount): Payment
    {
        return $this->paymentWithStatus($amount, PaymentStatus::Succeeded);
    }

    private function pendingPayment(int $amount): Payment
    {
        return $this->paymentWithStatus($amount, PaymentStatus::Pending);
    }

    private function paymentWithStatus(int $amount, PaymentStatus $status): Payment
    {
        $payable = TestPayable::factory()->create(['total_due' => $amount]);

        return Payment::factory()->for($payable, 'payable')->create([
            'gateway' => 'fake',
            'gateway_reference' => 'fake_pi_'.uniqid(),
            'amount' => $amount,
            'amount_refunded' => 0,
            'currency' => Currency::EUR,
            'status' => $status,
        ]);
    }
}
