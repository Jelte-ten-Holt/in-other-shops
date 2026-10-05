<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InOtherShops\Commerce\Order\Enums\RefundActorSource;
use InOtherShops\Commerce\Order\Events\RefundRecorded;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Actions\ProcessPaymentWebhook;
use InOtherShops\Payment\Actions\RefundPayment;
use InOtherShops\Payment\Contracts\PaymentGateway;
use InOtherShops\Payment\DTOs\GatewayRefund;
use InOtherShops\Payment\DTOs\PaymentSession;
use InOtherShops\Payment\DTOs\WebhookPayload;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Events\PaymentRefunded;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\Models\WebhookEvent;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Payment\Testing\FakePaymentGateway;
use InOtherShops\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * A refund that originates at the gateway (the Stripe dashboard, or the API used
 * by anyone but this app) lands via the webhook, updates the payment
 * monotonically, and the Commerce reconciliation listener records a Refund row,
 * with the reversed tax, for every refund the gateway lists that has none yet.
 * The interleavings with admin refunds are walked in
 * Commerce\Order\ReconcileRefundFromWebhookTest.
 */
final class ProcessPaymentWebhookRefundTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private ProcessPaymentWebhook $process;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway('fake');
        $this->app->make(PaymentGatewayManager::class)->extend('fake', fn (): FakePaymentGateway => $this->gateway);
        $this->process = $this->app->make(ProcessPaymentWebhook::class);
    }

    #[Test]
    public function a_dashboard_full_refund_webhook_refunds_the_payment_and_records_a_refund(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);

        $refundId = $this->gateway->recordOutsideRefund($payment, 1760);

        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded));

        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(1760, $payment->amount_refunded);

        $refund = $order->refunds()->sole();
        $this->assertSame(1760, $refund->amount);
        $this->assertSame($refundId, $refund->gateway_refund_id);
        $this->assertSame(RefundActorSource::Gateway, $refund->actor_source);
        // Full refund reverses the charged tax.
        $this->assertSame(210, collect($refund->taxSummary())->sum(fn ($l) => $l->tax));
    }

    #[Test]
    public function a_partial_dashboard_refund_marks_partially_refunded(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);

        $this->gateway->recordOutsideRefund($payment, 800);

        ($this->process)('fake', $this->gateway->simulateWebhook(
            $payment,
            PaymentStatus::Refunded, // event-type status; recomputed from amounts
        ));

        $payment->refresh();
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status,
            'status recomputed from amounts, not the event type');
        $this->assertSame(800, $payment->amount_refunded);
        $this->assertSame(800, $order->refunds()->sole()->amount);
    }

    #[Test]
    public function an_out_of_order_lower_cumulative_webhook_does_not_regress_the_refund(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);

        $this->gateway->recordOutsideRefund($payment, 800);
        $lower = $this->gateway->simulateWebhook($payment, PaymentStatus::PartiallyRefunded);
        $this->gateway->recordOutsideRefund($payment, 960);
        $higher = $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded);

        ($this->process)('fake', $higher);
        ($this->process)('fake', $lower);

        $payment->refresh();
        $this->assertSame(1760, $payment->amount_refunded, 'monotonic — a stale lower cumulative cannot regress it');
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame([800, 960], $order->refunds()->orderBy('id')->pluck('amount')->all(),
            'both refunds were recorded by the first event; the stale one recorded nothing new');
    }

    #[Test]
    public function a_redelivered_refund_webhook_is_idempotent(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);

        $this->gateway->recordOutsideRefund($payment, 1760);
        $request = $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded, eventId: 'evt_same');

        ($this->process)('fake', $request);
        ($this->process)('fake', $request); // same event id: one ledger row, nothing recorded twice

        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame(1, $order->refunds()->count());
        $this->assertSame(1760, $payment->refresh()->amount_refunded);
        $this->assertCount(1, $this->gateway->recordedRefundListings(),
            'the redelivery finds the total already moved and does not ask the gateway again');
    }

    #[Test]
    public function a_refund_whose_local_write_was_lost_is_reconciled_by_the_webhook(): void
    {
        // F34 residue recovery: the admin refund succeeded at the gateway but the
        // local amount_refunded write was lost. The charge.refunded webhook is the
        // backstop that brings the row consistent without operator action.
        $order = $this->order();
        $payment = $this->paymentFor($order);

        // Gateway refunded, but the local row missed it (still Succeeded, 0).
        $refundId = $this->gateway->refund($payment, 1760);
        $this->assertSame(0, $payment->refresh()->amount_refunded);

        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded));

        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(1760, $payment->amount_refunded, 'the webhook reconciles the lost local write');

        // The row is the admin's own refund, found in the gateway's list. Who
        // clicked is lost with the local write: the gateway is the actor.
        $refund = $order->refunds()->sole();
        $this->assertSame($refundId, $refund->gateway_refund_id);
        $this->assertSame(RefundActorSource::Gateway, $refund->actor_source);
    }

    #[Test]
    public function a_refund_event_dispatches_payment_refunded_with_the_gateways_refunds_oldest_first(): void
    {
        Event::fake([PaymentRefunded::class]);

        $payment = $this->paymentFor($this->order());

        $older = $this->gateway->recordOutsideRefund($payment, 500);
        $newer = $this->gateway->recordOutsideRefund($payment, 200);

        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded));

        Event::assertDispatched(
            PaymentRefunded::class,
            fn (PaymentRefunded $event): bool => $event->payment->is($payment)
                && $event->payment->amount_refunded === 700
                && array_map(fn (GatewayRefund $refund): array => [$refund->id, $refund->amount], $event->gatewayRefunds)
                    === [[$older, 500], [$newer, 200]],
        );
    }

    /**
     * Both shops share one Stripe account, so each endpoint also receives the
     * other shop's events. A refund event for a payment that is not ours must
     * not cost a call to the gateway.
     */
    #[Test]
    public function a_refund_event_for_a_payment_that_is_not_ours_makes_no_list_call(): void
    {
        $theirs = Payment::factory()->make([
            'gateway' => 'fake',
            'gateway_reference' => 'fake_pi_other_shop',
            'amount' => 1760,
            'currency' => Currency::EUR,
        ]);

        $this->gateway->recordOutsideRefund($theirs, 500);

        $returned = ($this->process)('fake', $this->gateway->simulateWebhook($theirs, PaymentStatus::Refunded));

        $this->assertNull($returned);
        $this->assertSame([], $this->gateway->recordedRefundListings());
        $this->assertSame(0, WebhookEvent::query()->count());
    }

    #[Test]
    public function the_echo_of_an_admin_refund_makes_no_list_call_and_dispatches_nothing(): void
    {
        $payment = $this->paymentFor($this->order());

        $this->app->make(RefundPayment::class)($payment, 500);

        Event::fake([PaymentRefunded::class]);

        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded));

        $this->assertSame([], $this->gateway->recordedRefundListings());
        $this->assertSame(500, $payment->refresh()->amount_refunded);
        Event::assertNotDispatched(PaymentRefunded::class);
    }

    /**
     * The list is fetched before the transaction opens, so a gateway call never
     * runs under the payment row lock, and a failing one leaves nothing behind:
     * the delivery answers non-2xx and the gateway retries it.
     */
    #[Test]
    public function a_failing_list_call_escapes_before_any_transaction_and_leaves_no_trace(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);

        $this->gateway->recordOutsideRefund($payment, 500);
        $this->gateway->markRefundListingErroring();

        // RefreshDatabase wraps the test in its own transaction; the action must
        // not have opened another by the time it asks the gateway.
        $outsideTheAction = DB::transactionLevel();

        try {
            ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded));
            $this->fail('Expected the gateway failure to escape.');
        } catch (RuntimeException $e) {
            $this->assertSame('fake: gateway unavailable', $e->getMessage());
        }

        $this->assertSame(
            [$outsideTheAction],
            array_column($this->gateway->recordedRefundListings(), 'transactionLevel'),
            'the list call ran inside the action\'s transaction',
        );
        $this->assertSame(0, WebhookEvent::query()->count(), 'no idempotency row, so the retry is processed');
        $this->assertSame(0, $payment->refresh()->amount_refunded);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(0, $order->refunds()->count());
    }

    /**
     * The rows are written inside the delivery's transaction, so a failure while
     * recording any of them takes the moved total and the idempotency row down
     * with it. Committing those without the rows would leave a payment no later
     * event repairs: every redelivery would be stale.
     */
    #[Test]
    public function a_failure_while_recording_a_refund_unwinds_the_whole_delivery(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);

        $this->gateway->recordOutsideRefund($payment, 500);
        $this->gateway->recordOutsideRefund($payment, 200);

        // The first refund records cleanly; the second fails once its row is in.
        Event::listen(RefundRecorded::class, function (RefundRecorded $event): void {
            if ($event->refund->amount === 200) {
                throw new RuntimeException('recording failed');
            }
        });

        try {
            ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded));
            $this->fail('Expected the failure to escape.');
        } catch (RuntimeException $e) {
            $this->assertSame('recording failed', $e->getMessage());
        }

        $this->assertSame(0, $order->refunds()->count(), 'the first refund\'s row must not survive alone');
        $this->assertSame(0, WebhookEvent::query()->count(), 'no idempotency row, so the retry is processed');
        $this->assertSame(0, $payment->refresh()->amount_refunded);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
    }

    /**
     * ListsRefunds is optional. A gateway without it still has its refund events
     * move the payment total; there is no list, so no row is recorded.
     */
    #[Test]
    public function a_refund_event_from_a_gateway_that_cannot_list_refunds_still_moves_the_payment_total(): void
    {
        Event::fake([PaymentRefunded::class]);

        $order = $this->order();
        $payment = $this->paymentFor($order);
        $payment->update(['gateway' => 'plain']);

        $this->app->make(PaymentGatewayManager::class)->extend('plain', fn (): PaymentGateway => $this->gatewayWithoutRefundList());

        $this->gateway->recordOutsideRefund($payment, 500);

        ($this->process)('plain', $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded));

        $this->assertSame(500, $payment->refresh()->amount_refunded);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        Event::assertDispatched(
            PaymentRefunded::class,
            fn (PaymentRefunded $event): bool => $event->payment->is($payment) && $event->gatewayRefunds === [],
        );
        $this->assertSame([], $this->gateway->recordedRefundListings());
    }

    /**
     * A PaymentGateway that is not a ListsRefunds. It reads webhooks the way the
     * fake does and supports nothing else.
     */
    private function gatewayWithoutRefundList(): PaymentGateway
    {
        return new class($this->gateway) implements PaymentGateway
        {
            public function __construct(private readonly FakePaymentGateway $fake) {}

            public function identifier(): string
            {
                return 'plain';
            }

            public function verifyWebhookSignature(Request $request): void {}

            public function parseWebhook(Request $request): ?WebhookPayload
            {
                return $this->fake->parseWebhook($request);
            }

            public function createSession(Payment $payment, string $returnUrl, string $cancelUrl, ?string $gatewayCustomerId = null): PaymentSession
            {
                throw new LogicException('Not needed for this test.');
            }

            public function retrieveSession(Payment $payment): PaymentSession
            {
                throw new LogicException('Not needed for this test.');
            }

            public function cancelSession(Payment $payment): void
            {
                throw new LogicException('Not needed for this test.');
            }

            public function refund(Payment $payment, ?int $amount = null): string
            {
                throw new LogicException('Not needed for this test.');
            }

            public function customerDashboardUrl(string $gatewayCustomerId): ?string
            {
                return null;
            }

            public function paymentDashboardUrl(Payment $payment): ?string
            {
                return null;
            }
        };
    }

    private function order(): Order
    {
        return Order::factory()->create([
            'total' => 1760,
            'tax' => 210,
            'tax_summary' => [
                ['rate_bps' => 1900, 'taxable_base' => 843, 'tax' => 160],
                ['rate_bps' => 700, 'taxable_base' => 707, 'tax' => 50],
            ],
        ]);
    }

    private function paymentFor(Order $order): Payment
    {
        return Payment::factory()->for($order, 'payable')->create([
            'gateway' => 'fake',
            'gateway_reference' => 'fake_pi_'.uniqid(),
            'amount' => 1760,
            'amount_refunded' => 0,
            'currency' => Currency::EUR,
            'status' => PaymentStatus::Succeeded,
        ]);
    }
}
