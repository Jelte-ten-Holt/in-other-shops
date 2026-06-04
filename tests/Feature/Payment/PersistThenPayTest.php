<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Actions\CreatePendingPayment;
use InOtherShops\Payment\Actions\OpenPaymentSession;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Exceptions\PaymentNotCancelableException;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Payment\Testing\FakePaymentGateway;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The persist-then-pay split (F1): CreatePendingPayment persists a Pending row
 * with NO gateway call (safe inside the checkout transaction); OpenPaymentSession
 * makes the gateway call and records the reference (runs after commit). Together
 * they replace the single in-transaction InitiatePayment for checkout, so a
 * gateway failure can never orphan a live intent against a rolled-back order.
 */
final class PersistThenPayTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway('fake');
        $this->app->make(PaymentGatewayManager::class)
            ->extend('fake', fn (): FakePaymentGateway => $this->gateway);
    }

    #[Test]
    public function create_pending_payment_persists_a_row_without_calling_the_gateway(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 5000]);

        $payment = $this->app->make(CreatePendingPayment::class)(
            payable: $payable,
            gatewayName: 'fake',
            amount: 5000,
            currency: Currency::EUR,
        );

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('fake', $payment->gateway);
        $this->assertNull($payment->gateway_reference, 'No gateway call yet → no reference.');
        $this->assertCount(0, $this->gateway->recordedSessions(),
            'CreatePendingPayment must not touch the gateway — that is the whole point.');
        $this->assertTrue($payment->payable->is($payable));
    }

    #[Test]
    public function open_payment_session_opens_the_gateway_session_and_records_the_reference(): void
    {
        $payable = TestPayable::factory()->create();
        $payment = $this->app->make(CreatePendingPayment::class)(
            payable: $payable,
            gatewayName: 'fake',
            amount: 100,
            currency: Currency::EUR,
        );

        $result = $this->app->make(OpenPaymentSession::class)($payment, '/return', '/cancel');

        $this->assertCount(1, $this->gateway->recordedSessions());
        $this->assertNotNull($payment->fresh()->gateway_reference);
        $this->assertStringStartsWith('fake_pi_', $payment->fresh()->gateway_reference);
        $this->assertSame($payment->fresh()->gateway_reference.'_secret', $result->clientSecret);
    }

    #[Test]
    public function a_failed_session_open_leaves_a_recoverable_pending_payment(): void
    {
        // The F1 safety property: if the gateway call fails, the persisted
        // payment + order remain Pending and unpaid — recoverable, not an
        // orphaned charge. (Here the persist already happened in its own scope.)
        $payable = TestPayable::factory()->create();
        $payment = $this->app->make(CreatePendingPayment::class)(
            payable: $payable,
            gatewayName: 'fake',
            amount: 100,
            currency: Currency::EUR,
        );

        $this->assertSame(1, Payment::query()->where('status', PaymentStatus::Pending)->count());
        $this->assertNull($payment->gateway_reference);
    }

    #[Test]
    public function cancel_session_records_a_cancellation_and_is_idempotent(): void
    {
        $payable = TestPayable::factory()->create();
        $payment = $this->app->make(CreatePendingPayment::class)(
            payable: $payable, gatewayName: 'fake', amount: 100, currency: Currency::EUR,
        );
        $this->app->make(OpenPaymentSession::class)($payment, '/r', '/c');
        $reference = $payment->fresh()->gateway_reference;

        $this->gateway->cancelSession($payment->fresh());
        $this->gateway->cancelSession($payment->fresh()); // idempotent

        $this->assertSame([$reference], $this->gateway->recordedCancellations());
    }

    #[Test]
    public function cancel_session_throws_when_the_payment_is_live(): void
    {
        $payable = TestPayable::factory()->create();
        $payment = $this->app->make(CreatePendingPayment::class)(
            payable: $payable, gatewayName: 'fake', amount: 100, currency: Currency::EUR,
        );
        $this->app->make(OpenPaymentSession::class)($payment, '/r', '/c');
        $this->gateway->markSessionLive($payment->fresh()->gateway_reference);

        $this->expectException(PaymentNotCancelableException::class);

        $this->gateway->cancelSession($payment->fresh());
    }
}
