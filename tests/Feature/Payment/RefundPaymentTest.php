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
    public function refunding_a_failed_payment_throws(): void
    {
        $payment = $this->paymentWithStatus(2500, PaymentStatus::Failed);

        $this->expectException(PaymentNotRefundableException::class);

        ($this->refund)($payment);
    }

    #[Test]
    public function refunding_an_already_fully_refunded_payment_throws(): void
    {
        $payment = $this->paymentWithStatus(2500, PaymentStatus::Refunded);
        $payment->update(['amount_refunded' => 2500]);

        $this->expectException(PaymentNotRefundableException::class);

        ($this->refund)($payment);
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
    public function refunding_a_zero_or_negative_amount_throws_invalid_argument(): void
    {
        $payment = $this->successfulPayment(2500);

        $this->expectException(InvalidArgumentException::class);

        ($this->refund)($payment, 0);
    }

    #[Test]
    public function it_dispatches_payment_refunded_with_the_payment_after_a_successful_refund(): void
    {
        Event::fake([PaymentRefunded::class]);

        $payment = $this->successfulPayment(2500);

        ($this->refund)($payment, 1000);

        Event::assertDispatched(
            PaymentRefunded::class,
            fn (PaymentRefunded $event) => $event->payment->is($payment),
        );
    }

    #[Test]
    public function it_does_not_dispatch_payment_refunded_when_validation_throws(): void
    {
        Event::fake([PaymentRefunded::class]);

        $payment = $this->pendingPayment(2500);

        try {
            ($this->refund)($payment);
        } catch (PaymentNotRefundableException) {
            // expected
        }

        Event::assertNotDispatched(PaymentRefunded::class);
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
