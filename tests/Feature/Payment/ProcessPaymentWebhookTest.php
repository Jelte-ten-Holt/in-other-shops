<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Actions\ProcessPaymentWebhook;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Events\PaymentFailed;
use InOtherShops\Payment\Events\PaymentSucceeded;
use InOtherShops\Payment\Exceptions\PaymentAmountMismatchException;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\Models\WebhookEvent;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Payment\Testing\FakePaymentGateway;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class ProcessPaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private ProcessPaymentWebhook $process;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway('fake');

        $manager = $this->app->make(PaymentGatewayManager::class);
        $manager->extend('fake', fn (): FakePaymentGateway => $this->gateway);

        $this->process = $this->app->make(ProcessPaymentWebhook::class);
    }

    #[Test]
    public function it_updates_a_pending_payment_to_succeeded_and_dispatches_payment_succeeded(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $payment = $this->paymentWithReference('fake_pi_ok', PaymentStatus::Pending);

        $request = $this->gateway->simulateWebhook($payment, PaymentStatus::Succeeded, 'evt_1');

        $returned = ($this->process)('fake', $request);

        $this->assertNotNull($returned);
        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);

        Event::assertDispatched(
            PaymentSucceeded::class,
            fn (PaymentSucceeded $event) => $event->payment->is($payment),
        );
        Event::assertNotDispatched(PaymentFailed::class);
    }

    #[Test]
    public function it_updates_a_pending_payment_to_failed_and_dispatches_payment_failed(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $payment = $this->paymentWithReference('fake_pi_fail', PaymentStatus::Pending);

        $request = $this->gateway->simulateWebhook($payment, PaymentStatus::Failed, 'evt_2');

        ($this->process)('fake', $request);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Failed, $payment->status);

        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    #[Test]
    public function a_duplicate_event_id_is_a_no_op_and_dispatches_no_event(): void
    {
        // Critical: webhook providers retry. The second delivery of the same
        // event_id must not flip status again, must not dispatch a second
        // PaymentSucceeded, and the payment row must remain at the value
        // produced by the first delivery.
        Event::fake([PaymentSucceeded::class]);

        $payment = $this->paymentWithReference('fake_pi_dup', PaymentStatus::Pending);

        $request1 = $this->gateway->simulateWebhook($payment, PaymentStatus::Succeeded, 'evt_dup');
        $request2 = $this->gateway->simulateWebhook($payment, PaymentStatus::Failed, 'evt_dup');

        ($this->process)('fake', $request1);
        $second = ($this->process)('fake', $request2);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status,
            'Replay must not overwrite the first-delivery status.');
        $this->assertNull($second,
            'Replay must return null to signal idempotent skip.');
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    #[Test]
    public function it_records_idempotency_rows_per_gateway_so_two_gateways_can_share_an_event_id(): void
    {
        // event_id is unique per (gateway, event_id), not globally. Two
        // different gateways legitimately use the same id namespace.
        $other = new FakePaymentGateway('other-gw');
        $this->app->make(PaymentGatewayManager::class)->extend('other-gw', fn () => $other);

        $payment1 = $this->paymentWithReference('fake_pi_a', PaymentStatus::Pending);
        $payment2 = $this->paymentWithReference('other_pi_a', PaymentStatus::Pending, gateway: 'other-gw');

        $req1 = $this->gateway->simulateWebhook($payment1, PaymentStatus::Succeeded, 'shared_id');
        $req2 = $other->simulateWebhook($payment2, PaymentStatus::Succeeded, 'shared_id');

        $this->assertNotNull(($this->process)('fake', $req1));
        $this->assertNotNull(($this->process)('other-gw', $req2),
            'Same event_id under a different gateway must not be deduped.');

        $this->assertSame(2, WebhookEvent::query()->count());
    }

    #[Test]
    public function a_webhook_for_an_unknown_payment_reference_is_dropped_without_recording_idempotency(): void
    {
        // This inverts an earlier pin that recorded the idempotency row on a
        // miss ("re-deliveries shouldn't re-search the DB"). That was the bug:
        // under persist-then-pay the gateway_reference is written by the pay
        // page, so a webhook can legitimately arrive BEFORE the reference
        // exists — and recording the row made every subsequent retry dedupe
        // against a delivery that did nothing. Order stuck Pending, customer
        // charged, permanently. A miss must leave no trace so a retry can land
        // once the reference is there (asserted by the next test).
        Event::fake([PaymentSucceeded::class]);

        // Build a payment so simulateWebhook works, but DON'T persist it via
        // this->paymentWithReference (which would let findPayment match).
        $orphan = Payment::factory()->make([
            'gateway' => 'fake',
            'gateway_reference' => 'fake_pi_orphan',
            'status' => PaymentStatus::Pending,
            'amount' => 1000,
            'currency' => Currency::EUR,
        ]);

        $request = $this->gateway->simulateWebhook($orphan, PaymentStatus::Succeeded, 'evt_orphan');

        $returned = ($this->process)('fake', $request);

        $this->assertNull($returned);
        Event::assertNotDispatched(PaymentSucceeded::class);
        $this->assertSame(0, WebhookEvent::query()->count(),
            'A miss must not record idempotency — it would swallow every retry.');
    }

    #[Test]
    public function a_retry_after_the_payment_reference_lands_processes_successfully(): void
    {
        // The recovery path the previous test exists to protect: delivery 1
        // arrives before the pay page wrote the gateway_reference (dropped,
        // no trace); the reference then lands; the gateway's retry of the SAME
        // event id must go through in full.
        Event::fake([PaymentSucceeded::class]);

        $orphan = Payment::factory()->make([
            'gateway' => 'fake',
            'gateway_reference' => 'fake_pi_early',
            'status' => PaymentStatus::Pending,
            'amount' => 2500,
            'currency' => Currency::EUR,
        ]);

        $early = $this->gateway->simulateWebhook($orphan, PaymentStatus::Succeeded, 'evt_early');
        $this->assertNull(($this->process)('fake', $early));

        // The pay page opens the intent and writes the reference.
        $payment = $this->paymentWithReference('fake_pi_early', PaymentStatus::Pending);

        $retry = $this->gateway->simulateWebhook($payment, PaymentStatus::Succeeded, 'evt_early');
        $returned = ($this->process)('fake', $retry);

        $this->assertNotNull($returned, 'The retry must not be treated as a duplicate.');
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        Event::assertDispatched(PaymentSucceeded::class);
        $this->assertSame(1, WebhookEvent::query()->count());
    }

    #[Test]
    public function a_declined_then_retried_payment_moves_failed_to_succeeded_and_dispatches(): void
    {
        // Stripe's payment_failed is attempt-level: the intent stays retryable
        // and a second attempt on it can succeed. The payment row must follow —
        // Failed is not terminal. Consumers rely on this transition to confirm
        // an order whose first attempt declined (see PaymentFailed's docblock).
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $payment = $this->paymentWithReference('fake_pi_retry', PaymentStatus::Pending);

        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Failed, 'evt_attempt_1'));
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);

        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Succeeded, 'evt_attempt_2'));

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status,
            'A failed ATTEMPT must not block the eventual success.');
        Event::assertDispatched(PaymentFailed::class);
        Event::assertDispatched(PaymentSucceeded::class);
    }

    #[Test]
    public function a_succeeded_payment_never_regresses_to_failed_on_a_late_failed_event(): void
    {
        // M6: out-of-order delivery. A `succeeded` settles the payment; a later
        // `failed` (a stale earlier-attempt event delivered late) must NOT flip a
        // settled, refundable payment back to Failed — that would strand real
        // money as unrefundable and mislead every downstream reader.
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $payment = $this->paymentWithReference('fake_pi_terminal', PaymentStatus::Pending);

        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Succeeded, 'evt_1'));
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);

        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Failed, 'evt_2'));

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status,
            'A settled Succeeded payment must not regress to Failed.');
        $this->assertSame(0, $payment->amount_refunded,
            'It stays refundable — a Failed payment is not.');
        Event::assertNotDispatched(PaymentFailed::class);
    }

    #[Test]
    public function a_payload_already_at_the_target_status_does_not_dispatch_a_duplicate_event(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $payment = $this->paymentWithReference('fake_pi_already', PaymentStatus::Succeeded);

        $request = $this->gateway->simulateWebhook($payment, PaymentStatus::Succeeded, 'evt_same');

        $returned = ($this->process)('fake', $request);

        // The action returns the payment but suppresses the event when status
        // didn't actually change. Otherwise consumers would log/notify on
        // gateway re-deliveries that are technically idempotent at the
        // payment-status level but new at the webhook level (rare, but
        // happens during retry storms).
        $this->assertNotNull($returned);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    #[Test]
    public function a_webhook_whose_amount_disagrees_with_the_payment_throws_and_does_not_update_status(): void
    {
        // M3 defense-in-depth: signature is verified, but the payload amount
        // disagrees with what we wrote at intent-creation time. Refuse the
        // transition rather than silently mark Succeeded for the wrong value.
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $payment = $this->paymentWithReference('fake_pi_amount_mismatch', PaymentStatus::Pending);

        $request = $this->gateway->simulateWebhook(
            $payment,
            PaymentStatus::Succeeded,
            'evt_amount_mismatch',
            amountOverride: 9999,
        );

        try {
            ($this->process)('fake', $request);
            $this->fail('Expected PaymentAmountMismatchException.');
        } catch (PaymentAmountMismatchException) {
            // expected
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status,
            'Status must not advance when payload amount disagrees.');

        Event::assertNotDispatched(PaymentSucceeded::class);

        // The throw rolls back the idempotency row so a corrected retry can
        // arrive — otherwise the wrong delivery would silently dedup the right
        // one out of existence.
        $this->assertSame(0, WebhookEvent::query()->count(),
            'Idempotency row must roll back on amount-mismatch throw.');
    }

    #[Test]
    public function a_webhook_whose_currency_disagrees_with_the_payment_throws_and_does_not_update_status(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $payment = $this->paymentWithReference('fake_pi_currency_mismatch', PaymentStatus::Pending);

        $request = $this->gateway->simulateWebhook(
            $payment,
            PaymentStatus::Succeeded,
            'evt_currency_mismatch',
            currencyOverride: 'usd',
        );

        try {
            ($this->process)('fake', $request);
            $this->fail('Expected PaymentAmountMismatchException.');
        } catch (PaymentAmountMismatchException) {
            // expected
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        Event::assertNotDispatched(PaymentSucceeded::class);
        $this->assertSame(0, WebhookEvent::query()->count());
    }

    #[Test]
    public function a_request_for_an_unregistered_gateway_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->process)('not-a-gateway', $this->gateway->simulateWebhook(
            $this->paymentWithReference('fake_pi_x', PaymentStatus::Pending),
            PaymentStatus::Succeeded,
        ));
    }

    private function paymentWithReference(string $reference, PaymentStatus $status, string $gateway = 'fake'): Payment
    {
        $payable = TestPayable::factory()->create(['total_due' => 2500]);

        return Payment::factory()->for($payable, 'payable')->create([
            'gateway' => $gateway,
            'gateway_reference' => $reference,
            'amount' => 2500,
            'currency' => Currency::EUR,
            'status' => $status,
        ]);
    }
}
