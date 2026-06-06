<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Enums\RefundActorSource;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Actions\ProcessPaymentWebhook;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Payment\Testing\FakePaymentGateway;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 3: a refund that originates at the gateway (Stripe dashboard / dispute /
 * API) lands via the webhook, updates the payment monotonically, and the Commerce
 * reconciliation listener records the matching Refund row with the reversed tax.
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

        $request = $this->gateway->simulateWebhook(
            $payment,
            PaymentStatus::Refunded,
            amountRefunded: 1760,
            gatewayRefundId: 're_dash',
        );

        ($this->process)('fake', $request);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(1760, $payment->amount_refunded);

        $refund = $order->refunds()->sole();
        $this->assertSame(1760, $refund->amount);
        $this->assertSame('re_dash', $refund->gateway_refund_id);
        $this->assertSame(RefundActorSource::Gateway, $refund->actor_source);
        // Full refund reverses the charged tax.
        $this->assertSame(210, collect($refund->taxSummary())->sum(fn ($l) => $l->tax));
    }

    #[Test]
    public function a_partial_dashboard_refund_marks_partially_refunded(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);

        ($this->process)('fake', $this->gateway->simulateWebhook(
            $payment,
            PaymentStatus::Refunded, // event-type status; recomputed from amounts
            amountRefunded: 800,
            gatewayRefundId: 're_partial',
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

        ($this->process)('fake', $this->gateway->simulateWebhook(
            $payment, PaymentStatus::Refunded, amountRefunded: 1760, gatewayRefundId: 're_hi',
        ));
        ($this->process)('fake', $this->gateway->simulateWebhook(
            $payment, PaymentStatus::PartiallyRefunded, amountRefunded: 800, gatewayRefundId: 're_lo',
        ));

        $payment->refresh();
        $this->assertSame(1760, $payment->amount_refunded, 'monotonic — a stale lower cumulative cannot regress it');
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(1, $order->refunds()->count(), 'the stale event recorded no new refund');
    }

    #[Test]
    public function a_redelivered_refund_webhook_is_idempotent(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);

        $request = $this->gateway->simulateWebhook(
            $payment, PaymentStatus::Refunded, eventId: 'evt_same', amountRefunded: 1760, gatewayRefundId: 're_x',
        );

        ($this->process)('fake', $request);
        ($this->process)('fake', $request); // same event id → deduped by the webhook ledger

        $this->assertSame(1, $order->refunds()->count());
        $this->assertSame(1760, $payment->refresh()->amount_refunded);
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
        $this->gateway->refund($payment, 1760);
        $this->assertSame(0, $payment->refresh()->amount_refunded);

        ($this->process)('fake', $this->gateway->simulateWebhook(
            $payment, PaymentStatus::Refunded, amountRefunded: 1760, gatewayRefundId: 're_recover',
        ));

        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(1760, $payment->amount_refunded, 'the webhook reconciles the lost local write');
        $this->assertSame(1, $order->refunds()->count());
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
