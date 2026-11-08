<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use InOtherShops\Commerce\Order\Actions\RefundOrder;
use InOtherShops\Commerce\Order\DTOs\RefundActor;
use InOtherShops\Commerce\Order\Events\RefundFailed;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Actions\ProcessPaymentWebhook;
use InOtherShops\Payment\Contracts\PaymentGateway;
use InOtherShops\Payment\DTOs\GatewayRefundFailure;
use InOtherShops\Payment\DTOs\PaymentSession;
use InOtherShops\Payment\DTOs\WebhookPayload;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Events\PaymentRefunded;
use InOtherShops\Payment\Events\PaymentRefundFailed;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\Models\WebhookEvent;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Payment\Testing\FakePaymentGateway;
use InOtherShops\Tests\TestCase;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * R4: a refund the gateway accepted can fail later (a closed or expired card),
 * and the money goes back to the shop's balance. The failure lowers the
 * payment's refunded total and takes the Refund row out of the order's refunded
 * total and its VAT reversal, so the order reads unrefunded again and a later
 * genuine refund is recorded in full. The live Stripe shape is in
 * Payment\Stripe\ProcessStripeWebhookTest.
 */
final class ProcessPaymentWebhookRefundFailureTest extends TestCase
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
    public function a_failed_full_refund_puts_the_payment_back_and_takes_the_refund_out_of_the_order(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);
        $refundId = $this->gateway->recordOutsideRefund($payment, 1760);
        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded));

        $paymentEvents = 0;
        $refundEvents = 0;
        Event::listen(PaymentRefundFailed::class, function () use (&$paymentEvents): void {
            $paymentEvents++;
        });
        Event::listen(RefundFailed::class, function () use (&$refundEvents): void {
            $refundEvents++;
        });

        $failure = $this->gateway->failRefund($payment, $refundId);
        ($this->process)('fake', $this->gateway->simulateRefundFailure($payment->refresh(), $failure));

        $payment->refresh();
        $this->assertSame(0, $payment->amount_refunded);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);

        $refund = $order->refunds()->sole();
        $this->assertTrue($refund->isFailed());
        $this->assertSame('expired_or_canceled_card', $refund->failure_reason);
        $this->assertSame(1760, $refund->amount, 'the row keeps what was attempted');

        $this->assertSame(0, $order->refundedTotal());
        $this->assertFalse($order->isRefunded());
        $this->assertFalse($order->isPartiallyRefunded());
        $this->assertSame(1, $paymentEvents);
        $this->assertSame(1, $refundEvents);
    }

    #[Test]
    public function a_failed_partial_refund_lowers_the_totals_by_its_own_amount(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);
        $this->gateway->recordOutsideRefund($payment, 500);
        $failing = $this->gateway->recordOutsideRefund($payment, 300);
        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::PartiallyRefunded));
        $this->assertSame(800, $payment->refresh()->amount_refunded);

        $failure = $this->gateway->failRefund($payment, $failing);
        ($this->process)('fake', $this->gateway->simulateRefundFailure($payment, $failure));

        $payment->refresh();
        $this->assertSame(500, $payment->amount_refunded);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        $this->assertSame(500, $order->refundedTotal());
        $this->assertSame(1, $order->refunds()->whereNotNull('failed_at')->count());
    }

    /**
     * The case the fix exists for. Before R4, the stored total stayed at 1760
     * after the failure, so the re-refund's event carried a cumulative no
     * higher than it and was dropped as stale: no row, no VAT reversal.
     */
    #[Test]
    public function a_refund_after_a_failed_one_is_recorded_and_reverses_the_vat_in_full(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);
        $first = $this->gateway->recordOutsideRefund($payment, 1760);
        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded));
        $failure = $this->gateway->failRefund($payment, $first);
        ($this->process)('fake', $this->gateway->simulateRefundFailure($payment->refresh(), $failure));

        $second = $this->gateway->recordOutsideRefund($payment->refresh(), 1760);
        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded));

        $payment->refresh();
        $this->assertSame(1760, $payment->amount_refunded);
        $this->assertSame(PaymentStatus::Refunded, $payment->status);

        $standing = $order->refunds()->notFailed()->sole();
        $this->assertSame($second, $standing->gateway_refund_id);
        $this->assertSame(1760, $order->refundedTotal());
        $this->assertSame(210, $this->taxOf($standing), 'the failed refund reversed nothing that stands');
        $this->assertSame(210, $order->refunds()->notFailed()->get()->sum(fn (Refund $refund): int => $this->taxOf($refund)));
    }

    /**
     * Stripe does not order its deliveries. A charge.refunded sent before the
     * failure still counts the failed refund in its cumulative; the gateway's
     * list, read when the event is processed, no longer does.
     */
    #[Test]
    public function a_refund_event_from_before_the_failure_does_not_raise_the_total_again(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);
        $refundId = $this->gateway->recordOutsideRefund($payment, 1760);
        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded));
        $late = $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded, eventId: 'evt_late');

        $failure = $this->gateway->failRefund($payment, $refundId);
        ($this->process)('fake', $this->gateway->simulateRefundFailure($payment->refresh(), $failure));

        $dispatched = false;
        Event::listen(PaymentRefunded::class, function () use (&$dispatched): void {
            $dispatched = true;
        });

        ($this->process)('fake', $late);

        $payment->refresh();
        $this->assertSame(0, $payment->amount_refunded);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertFalse($dispatched);
        $this->assertSame(1, $order->refunds()->count());
    }

    /**
     * Found by the 2026-11-08 pathology review: a refund event retried past
     * the failure (a deploy, a list call that threw) already leaves the failed
     * refund out, because the list caps it. Subtracting the failed amount
     * afterwards took the standing refund with it (500 - 300 = 200). Read from
     * the list, both delivery orders end where the gateway is.
     */
    #[Test]
    public function a_failure_after_its_refund_event_was_retried_past_it_keeps_the_standing_refund(): void
    {
        [$order, $payment, $failure] = $this->standingRefundAndOneThatFails();
        $refundEvent = $this->gateway->simulateWebhook($payment, PaymentStatus::PartiallyRefunded, eventId: 'evt_x_refunded');
        $this->gateway->failRefund($payment, $failure);

        ($this->process)('fake', $refundEvent);
        ($this->process)('fake', $this->gateway->simulateRefundFailure($payment->refresh(), $this->failureOf($failure, 300)));

        $this->assertSame(500, $payment->refresh()->amount_refunded);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        $this->assertSame(500, $order->refundedTotal());
    }

    #[Test]
    public function a_failure_before_its_refund_event_keeps_the_standing_refund(): void
    {
        [$order, $payment, $failure] = $this->standingRefundAndOneThatFails();
        $refundEvent = $this->gateway->simulateWebhook($payment, PaymentStatus::PartiallyRefunded, eventId: 'evt_x_refunded');
        $this->gateway->failRefund($payment, $failure);

        ($this->process)('fake', $this->gateway->simulateRefundFailure($payment->refresh(), $this->failureOf($failure, 300)));
        ($this->process)('fake', $refundEvent);

        $this->assertSame(500, $payment->refresh()->amount_refunded);
        $this->assertSame(500, $order->refundedTotal());
    }

    /**
     * The gateway reports a failure once, but nothing here should depend on
     * it: a second failure event for the same refund, under its own event id,
     * changes nothing.
     */
    #[Test]
    public function a_second_failure_event_for_the_same_refund_changes_nothing(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);
        $this->gateway->recordOutsideRefund($payment, 1000);
        $failing = $this->gateway->recordOutsideRefund($payment, 500);
        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::PartiallyRefunded));
        $failure = $this->gateway->failRefund($payment, $failing);

        ($this->process)('fake', $this->gateway->simulateRefundFailure($payment->refresh(), $failure, eventId: 'evt_a'));
        ($this->process)('fake', $this->gateway->simulateRefundFailure($payment->refresh(), $failure, eventId: 'evt_b'));

        $this->assertSame(1000, $payment->refresh()->amount_refunded);
        $this->assertSame(1000, $order->refundedTotal());
    }

    /**
     * The path the fix exists for: the admin refunds in full, the refund
     * fails, and the admin refunds again. The second refund goes through
     * RefundPayment's cap and reverses the VAT in full.
     */
    #[Test]
    public function an_admin_refund_that_fails_can_be_made_again(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);
        $first = $this->app->make(RefundOrder::class)($order, RefundActor::admin('1', 'Shop admin'));
        $this->assertSame(1760, $payment->refresh()->amount_refunded);

        $failure = $this->gateway->failRefund($payment, $first->gateway_refund_id);
        ($this->process)('fake', $this->gateway->simulateRefundFailure($payment->refresh(), $failure));

        $payment->refresh();
        $this->assertSame(0, $payment->amount_refunded);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertTrue($first->refresh()->isFailed());

        $second = $this->app->make(RefundOrder::class)($order->refresh(), RefundActor::admin('1', 'Shop admin'));

        $this->assertSame(1760, $second->amount);
        $this->assertSame(1760, $payment->refresh()->amount_refunded);
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(1760, $order->refundedTotal());
        $this->assertSame(210, $this->taxOf($second));
    }

    /**
     * A gateway that cannot list refunds has nothing to read the total from,
     * so the failure subtracts its amount, never below zero.
     */
    #[Test]
    public function a_gateway_that_cannot_list_refunds_subtracts_the_failed_amount_never_below_zero(): void
    {
        $this->app->make(PaymentGatewayManager::class)->extend('fake', fn (): PaymentGateway => $this->gatewayWithoutRefundList());
        $order = $this->order();
        $payment = $this->paymentFor($order);
        $payment->update(['amount_refunded' => 800, 'status' => PaymentStatus::PartiallyRefunded]);

        ($this->process)('fake', $this->gateway->simulateRefundFailure($payment, new GatewayRefundFailure(id: 'fake_re_a', amount: 300)));
        $this->assertSame(500, $payment->refresh()->amount_refunded);

        ($this->process)('fake', $this->gateway->simulateRefundFailure($payment, new GatewayRefundFailure(id: 'fake_re_b', amount: 900)));
        $this->assertSame(0, $payment->refresh()->amount_refunded);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
    }

    #[Test]
    public function a_redelivered_failure_is_applied_once(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);
        $this->gateway->recordOutsideRefund($payment, 1000);
        $failing = $this->gateway->recordOutsideRefund($payment, 760);
        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::Refunded));
        $failure = $this->gateway->failRefund($payment, $failing);

        ($this->process)('fake', $this->gateway->simulateRefundFailure($payment->refresh(), $failure, eventId: 'evt_failed'));
        ($this->process)('fake', $this->gateway->simulateRefundFailure($payment->refresh(), $failure, eventId: 'evt_failed'));

        $this->assertSame(1000, $payment->refresh()->amount_refunded);
        $this->assertSame(1000, $order->refundedTotal());
    }

    /**
     * The failure can arrive before the refund event that would have recorded
     * the row. The payment total is right either way (Stripe's own is back
     * where it was); throwing would have the gateway retry a delivery that can
     * never succeed.
     */
    #[Test]
    public function a_failure_of_a_refund_this_shop_never_recorded_is_logged_and_answered(): void
    {
        Log::spy();
        $order = $this->order();
        $payment = $this->paymentFor($order);
        $refundId = $this->gateway->recordOutsideRefund($payment, 500);
        $failure = $this->gateway->failRefund($payment, $refundId);

        $returned = ($this->process)('fake', $this->gateway->simulateRefundFailure($payment, $failure));

        $this->assertTrue($payment->is($returned));
        $payment->refresh();
        $this->assertSame(0, $payment->amount_refunded, 'never below zero');
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(0, $order->refunds()->count());
        $this->assertSame(1, WebhookEvent::query()->count());
        Log::shouldHaveReceived('warning')
            ->with('A refund failed at the gateway that this shop never recorded', Mockery::on(
                fn (array $context): bool => $context['gateway_refund_id'] === $refundId && $context['failed_amount'] === 500,
            ))
            ->once();
    }

    /**
     * One Stripe account can serve two shops, and then each endpoint receives
     * the other's events. A failure on a payment that isn't ours is not a lost
     * event to retry.
     */
    #[Test]
    public function a_failure_on_a_payment_that_is_not_ours_is_dropped(): void
    {
        $theirs = Payment::factory()->make([
            'gateway' => 'fake',
            'gateway_reference' => 'fake_pi_theirs',
            'amount' => 1000,
            'currency' => Currency::EUR,
        ]);

        $returned = ($this->process)('fake', $this->gateway->simulateRefundFailure(
            $theirs,
            new GatewayRefundFailure(id: 'fake_re_theirs', amount: 1000),
        ));

        $this->assertNull($returned);
        $this->assertSame(0, WebhookEvent::query()->count());
    }

    /**
     * A standing 500 refund, recorded, and a 300 refund made at the gateway
     * whose own refund event has not been processed yet.
     *
     * @return array{Order, Payment, string}
     */
    private function standingRefundAndOneThatFails(): array
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);
        $this->gateway->recordOutsideRefund($payment, 500);
        ($this->process)('fake', $this->gateway->simulateWebhook($payment, PaymentStatus::PartiallyRefunded));
        $this->assertSame(500, $payment->refresh()->amount_refunded);

        return [$order, $payment, $this->gateway->recordOutsideRefund($payment, 300)];
    }

    private function failureOf(string $refundId, int $amount): GatewayRefundFailure
    {
        return new GatewayRefundFailure(id: $refundId, amount: $amount, reason: 'expired_or_canceled_card');
    }

    /** The fake without ListsRefunds: refund webhooks only, no list to read. */
    private function gatewayWithoutRefundList(): PaymentGateway
    {
        return new class($this->gateway) implements PaymentGateway
        {
            public function __construct(private readonly FakePaymentGateway $fake) {}

            public function identifier(): string
            {
                return 'fake';
            }

            public function verifyWebhookSignature(Request $request): void {}

            public function parseWebhook(Request $request): ?WebhookPayload
            {
                return $this->fake->parseWebhook($request);
            }

            public function createSession(Payment $payment, string $returnUrl, string $cancelUrl, ?string $gatewayCustomerId = null): PaymentSession
            {
                throw new LogicException('not used');
            }

            public function retrieveSession(Payment $payment): PaymentSession
            {
                throw new LogicException('not used');
            }

            public function cancelSession(Payment $payment): void {}

            public function refund(Payment $payment, ?int $amount = null): string
            {
                throw new LogicException('not used');
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

    private function taxOf(Refund $refund): int
    {
        return array_sum(array_map(fn ($line): int => $line->tax, $refund->taxSummary()));
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
