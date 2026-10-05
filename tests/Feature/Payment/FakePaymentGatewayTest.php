<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment;

use InOtherShops\Payment\DTOs\GatewayRefund;
use InOtherShops\Payment\DTOs\PaymentCustomerData;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Exceptions\RefundAmountExceededException;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\Testing\FakePaymentGateway;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class FakePaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function identifier_defaults_to_fake_and_can_be_overridden(): void
    {
        $this->assertSame('fake', (new FakePaymentGateway)->identifier());
        $this->assertSame('stripe', (new FakePaymentGateway('stripe'))->identifier());
    }

    #[Test]
    public function create_session_returns_a_synthetic_reference_and_records_the_call(): void
    {
        $gateway = new FakePaymentGateway;
        $payment = $this->paymentFor(2500);

        $session = $gateway->createSession($payment, '/return', '/cancel', null);

        $this->assertNotSame('', $session->gatewayReference);
        $this->assertStringStartsWith('fake_pi_', $session->gatewayReference);
        $this->assertSame($session->gatewayReference.'_secret', $session->clientSecret);

        $recorded = $gateway->recordedSessions();
        $this->assertCount(1, $recorded);
        $this->assertSame($payment->id, $recorded[0]['payment']->id);
        $this->assertSame('/return', $recorded[0]['returnUrl']);
    }

    #[Test]
    public function create_session_generates_distinct_references_per_call(): void
    {
        $gateway = new FakePaymentGateway;

        $first = $gateway->createSession($this->paymentFor(100), '/r', '/c');
        $second = $gateway->createSession($this->paymentFor(200), '/r', '/c');

        $this->assertNotSame($first->gatewayReference, $second->gatewayReference);
    }

    #[Test]
    public function retrieve_session_throws_when_payment_has_no_reference(): void
    {
        $gateway = new FakePaymentGateway;
        $payment = $this->paymentFor(2500);

        $this->expectException(RuntimeException::class);
        $gateway->retrieveSession($payment);
    }

    #[Test]
    public function retrieve_session_returns_a_session_for_a_payment_with_a_reference(): void
    {
        $gateway = new FakePaymentGateway;
        $payment = $this->paymentFor(2500);
        $payment->update(['gateway_reference' => 'fake_pi_existing']);

        $session = $gateway->retrieveSession($payment);

        $this->assertSame('fake_pi_existing', $session->gatewayReference);
        $this->assertSame('fake_pi_existing_secret', $session->clientSecret);
    }

    #[Test]
    public function simulate_webhook_round_trips_through_parse_webhook(): void
    {
        $gateway = new FakePaymentGateway;
        $payment = $this->paymentFor(2500);
        $payment->update(['gateway_reference' => 'fake_pi_xyz']);

        $request = $gateway->simulateWebhook($payment, PaymentStatus::Succeeded, 'fake_evt_1');

        $gateway->verifyWebhookSignature($request);
        $payload = $gateway->parseWebhook($request);

        $this->assertSame('fake_pi_xyz', $payload->gatewayReference);
        $this->assertSame(PaymentStatus::Succeeded, $payload->status);
        $this->assertSame('fake_evt_1', $payload->eventId);
    }

    #[Test]
    public function refund_records_the_amount(): void
    {
        $gateway = new FakePaymentGateway;
        $payment = $this->paymentFor(2500);
        $payment->update(['gateway_reference' => 'fake_pi_xyz']);

        $gateway->refund($payment, 1000);

        $this->assertCount(1, $gateway->recordedRefunds());
        $this->assertSame(1000, $gateway->recordedRefunds()[0]['amount']);
    }

    #[Test]
    public function refund_rejects_overrefund_without_recording_it(): void
    {
        $gateway = new FakePaymentGateway;
        $payment = $this->paymentFor(2500);
        $payment->update(['gateway_reference' => 'fake_pi_xyz']);

        $gateway->refund($payment, 1000);

        try {
            $gateway->refund($payment, 9999);
            $this->fail('Expected RefundAmountExceededException.');
        } catch (RefundAmountExceededException) {
            // expected
        }

        $this->assertCount(1, $gateway->recordedRefunds(),
            'A rejected over-refund must not append a recorded-refund entry.');
        $this->assertSame(1000, $gateway->recordedRefunds()[0]['amount']);
    }

    #[Test]
    public function a_full_refund_is_recorded_with_the_amount_it_resolved_to(): void
    {
        $gateway = new FakePaymentGateway;
        $payment = $this->paymentFor(2500);
        $payment->update(['gateway_reference' => 'fake_pi_xyz']);

        $gateway->refund($payment, 1000);
        $gateway->refund($payment);

        $this->assertSame(1500, $gateway->recordedRefunds()[1]['amount']);
    }

    #[Test]
    public function list_refunds_returns_every_refund_on_the_payment_oldest_first_whoever_made_it(): void
    {
        $gateway = new FakePaymentGateway;
        $payment = $this->paymentFor(2500);
        $payment->update(['gateway_reference' => 'fake_pi_xyz']);
        $other = $this->paymentFor(900);
        $other->update(['gateway_reference' => 'fake_pi_other']);

        $outside = $gateway->recordOutsideRefund($payment, 400);
        $own = $gateway->refund($payment, 1000);
        $gateway->recordOutsideRefund($other, 900);

        $this->assertEquals(
            [new GatewayRefund($outside, 400), new GatewayRefund($own, 1000)],
            $gateway->listRefunds($payment),
        );
        $this->assertCount(1, $gateway->recordedRefunds(), 'an outside refund is not one of the app\'s own calls');
    }

    #[Test]
    public function an_outside_refund_counts_against_the_same_cap_as_the_apps_own(): void
    {
        $gateway = new FakePaymentGateway;
        $payment = $this->paymentFor(2500);
        $payment->update(['gateway_reference' => 'fake_pi_xyz']);

        $gateway->recordOutsideRefund($payment, 2000);

        try {
            $gateway->refund($payment, 501);
            $this->fail('Expected RefundAmountExceededException.');
        } catch (RefundAmountExceededException) {
            // expected
        }

        $this->assertSame([], $gateway->recordedRefunds());
        $this->assertCount(1, $gateway->listRefunds($payment));
    }

    #[Test]
    public function a_refund_webhook_carries_the_cumulative_the_ledger_held_when_it_was_built(): void
    {
        $gateway = new FakePaymentGateway;
        $payment = $this->paymentFor(2500);
        $payment->update(['gateway_reference' => 'fake_pi_xyz']);

        $gateway->recordOutsideRefund($payment, 400);
        $first = $gateway->simulateWebhook($payment, PaymentStatus::Refunded);
        $gateway->refund($payment, 1000);
        $second = $gateway->simulateWebhook($payment, PaymentStatus::Refunded);

        $this->assertSame(400, $gateway->parseWebhook($first)->amountRefunded,
            'an event built before a later refund keeps its own snapshot');
        $this->assertSame(1400, $gateway->parseWebhook($second)->amountRefunded);
        $this->assertNull(
            $gateway->parseWebhook($gateway->simulateWebhook($payment, PaymentStatus::Succeeded))->amountRefunded,
            'only a refund event carries a cumulative',
        );
    }

    #[Test]
    public function create_customer_returns_a_fake_id_and_records_the_payload(): void
    {
        $gateway = new FakePaymentGateway;

        $id = $gateway->createCustomer(new PaymentCustomerData(email: 'a@b.test', name: 'A'));

        $this->assertStringStartsWith('fake_cust_', $id);
        $this->assertCount(1, $gateway->recordedCustomers());
        $this->assertSame('a@b.test', $gateway->recordedCustomers()[0]->email);
    }

    private function paymentFor(int $amount): Payment
    {
        $payable = TestPayable::factory()->create(['total_due' => $amount]);

        return Payment::factory()->for($payable, 'payable')->create([
            'amount' => $amount,
            'status' => PaymentStatus::Pending,
        ]);
    }
}
