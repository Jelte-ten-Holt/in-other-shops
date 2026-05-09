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
    public function a_webhook_for_an_unknown_payment_reference_returns_null_and_dispatches_no_event(): void
    {
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
        // Idempotency row IS recorded — re-deliveries of the orphan event
        // shouldn't re-search the DB. Pin this so a future "lazy" change
        // that skips the idempotency write on miss doesn't regress.
        $this->assertSame(1, WebhookEvent::query()->count());
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
