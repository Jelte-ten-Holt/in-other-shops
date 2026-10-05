<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InOtherShops\Commerce\Order\Actions\RefundOrder;
use InOtherShops\Commerce\Order\DTOs\RefundActor;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Actions\ProcessPaymentWebhook;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Payment\Testing\FakePaymentGateway;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Refund rows against the gateway's own refund list, one interleaving at a time:
 * refunds made at the gateway by someone else ("outside": the Stripe dashboard)
 * and refunds made from the admin, with their events arriving in every order
 * that changes the outcome. The walk is docs/briefs/dashboard-refund-brief.md § 4.
 *
 * Every test asserts each row's own amount and per-bracket VAT. The row count
 * and the sum of amounts also come out right when every row of one event is
 * anchored to the payment total, which reverses too little VAT.
 *
 * The order charged 1760 with VAT {1900: 160, 700: 50}. Reversed VAT is anchored
 * to the cumulative refunded, so the per-bracket targets used below are:
 * 300 → {27, 9}, 500 → {46, 14}, 700 → {64, 20}, 800 → {73, 22},
 * 1000 → {91, 28}, 1460 → {133, 41}, 1760 → {160, 50}.
 */
final class ReconcileRefundFromWebhookTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private ProcessPaymentWebhook $process;

    private RefundOrder $refundOrder;

    private Order $order;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway('fake');
        $this->app->make(PaymentGatewayManager::class)->extend('fake', fn (): FakePaymentGateway => $this->gateway);
        $this->process = $this->app->make(ProcessPaymentWebhook::class);
        $this->refundOrder = $this->app->make(RefundOrder::class);

        $this->order = Order::factory()->create([
            'status' => OrderStatus::Confirmed,
            'total' => 1760,
            'tax' => 210,
            'tax_summary' => [
                ['rate_bps' => 1900, 'taxable_base' => 843, 'tax' => 160],
                ['rate_bps' => 700, 'taxable_base' => 707, 'tax' => 50],
            ],
        ]);

        $this->payment = $this->paymentFor($this->order);
    }

    #[Test]
    public function an_admin_refund_followed_by_an_outside_one_records_only_the_new_refund(): void
    {
        $this->deliver($this->adminRefund(500));
        $this->deliver($this->outsideRefund(200));

        $this->assertSame([
            ['amount' => 500, 'tax' => [1900 => 46, 700 => 14], 'by' => 'admin'],
            ['amount' => 200, 'tax' => [1900 => 18, 700 => 6], 'by' => 'gateway'],
        ], $this->rows());
        $this->assertSame(700, $this->payment->refresh()->amount_refunded);
    }

    #[Test]
    public function two_outside_refunds_are_both_recorded_by_the_first_event_to_arrive(): void
    {
        $first = $this->outsideRefund(500);
        $second = $this->outsideRefund(200);

        $this->deliver($first);

        $expected = [
            ['amount' => 500, 'tax' => [1900 => 46, 700 => 14], 'by' => 'gateway'],
            ['amount' => 200, 'tax' => [1900 => 18, 700 => 6], 'by' => 'gateway'],
        ];

        $this->assertSame($expected, $this->rows());
        $this->assertSame(
            ['fake_re_000001', 'fake_re_000002'],
            $this->order->refunds()->orderBy('id')->pluck('gateway_refund_id')->all(),
            'each row carries its own gateway refund id',
        );
        $this->assertSame(500, $this->payment->refresh()->amount_refunded,
            'between the two events the rows are ahead of the payment total');

        $this->deliver($second);

        $this->assertSame($expected, $this->rows(), 'the second event finds both refunds recorded');
        $this->assertSame(700, $this->payment->refresh()->amount_refunded);
    }

    #[Test]
    public function two_outside_refunds_whose_events_arrive_in_reverse_are_both_recorded_once(): void
    {
        $first = $this->outsideRefund(500);
        $second = $this->outsideRefund(200);

        $this->deliver($second);

        // The payment total is already at 700 when the older refund is recorded,
        // so its row carries the VAT of the whole 700 and the newer row none. The
        // split differs from the in-order case; the reversed total does not.
        $expected = [
            ['amount' => 500, 'tax' => [1900 => 64, 700 => 20], 'by' => 'gateway'],
            ['amount' => 200, 'tax' => [], 'by' => 'gateway'],
        ];

        $this->assertSame($expected, $this->rows());
        $this->assertSame(700, $this->payment->refresh()->amount_refunded);

        $this->deliver($first);

        $this->assertSame($expected, $this->rows(), 'the stale first event records nothing');
        $this->assertSame(700, $this->payment->refresh()->amount_refunded);
        $this->assertCount(1, $this->gateway->recordedRefundListings(), 'a stale event costs no list call');
    }

    /**
     * The sequence an earlier design got wrong. When the admin refund is
     * recorded the rows (700) are ahead of the payment total (800 after it,
     * against 1000 really refunded). Anchoring its VAT to the payment total
     * reversed 95c in all where 119c is due.
     */
    #[Test]
    public function an_admin_refund_made_while_the_rows_are_ahead_of_the_payment_total_reverses_the_right_vat(): void
    {
        $first = $this->outsideRefund(500);
        $second = $this->outsideRefund(200);

        $this->deliver($first);
        $echo = $this->adminRefund(300);
        $this->deliver($second);
        $this->deliver($echo);

        $this->assertSame([
            ['amount' => 500, 'tax' => [1900 => 46, 700 => 14], 'by' => 'gateway'],
            ['amount' => 200, 'tax' => [1900 => 18, 700 => 6], 'by' => 'gateway'],
            ['amount' => 300, 'tax' => [1900 => 27, 700 => 8], 'by' => 'admin'],
        ], $this->rows());
        $this->assertSame([1900 => 91, 700 => 28], $this->reversedTax());
        $this->assertNoNegativeTaxLine();
        $this->assertSame(1000, $this->payment->refresh()->amount_refunded);
    }

    #[Test]
    public function the_same_sequence_ending_in_a_full_refund_reverses_every_cent_of_vat(): void
    {
        $first = $this->outsideRefund(500);
        $second = $this->outsideRefund(960);

        $this->deliver($first);
        $echo = $this->adminRefund(300);
        $this->deliver($second);
        $this->deliver($echo);

        $this->assertSame([
            ['amount' => 500, 'tax' => [1900 => 46, 700 => 14], 'by' => 'gateway'],
            ['amount' => 960, 'tax' => [1900 => 87, 700 => 27], 'by' => 'gateway'],
            ['amount' => 300, 'tax' => [1900 => 27, 700 => 9], 'by' => 'admin'],
        ], $this->rows());
        $this->assertSame([1900 => 160, 700 => 50], $this->reversedTax());
        $this->assertNoNegativeTaxLine();
        $this->assertSame(1760, $this->payment->refresh()->amount_refunded);
        $this->assertSame(PaymentStatus::Refunded, $this->payment->status);
    }

    #[Test]
    public function an_admin_refund_made_before_a_larger_outside_refunds_event_arrives_leaves_both_recorded(): void
    {
        $outside = $this->outsideRefund(500);
        $echo = $this->adminRefund(300);

        $this->deliver($outside);

        $expected = [
            ['amount' => 300, 'tax' => [1900 => 27, 700 => 9], 'by' => 'admin'],
            ['amount' => 500, 'tax' => [1900 => 46, 700 => 13], 'by' => 'gateway'],
        ];

        $this->assertSame($expected, $this->rows());
        $this->assertSame(500, $this->payment->refresh()->amount_refunded,
            'the outside event only knows of its own refund; the rows are ahead until the echo');

        $this->deliver($echo);

        $this->assertSame($expected, $this->rows());
        $this->assertSame([1900 => 73, 700 => 22], $this->reversedTax());
        $this->assertSame(800, $this->payment->refresh()->amount_refunded);
    }

    #[Test]
    public function an_outside_refund_no_larger_than_a_later_admin_refund_is_recorded_one_event_later(): void
    {
        $outside = $this->outsideRefund(300);
        $echo = $this->adminRefund(500);

        $this->deliver($outside);

        // The admin's local write (500) is already past the outside event's
        // cumulative (300): the event is stale and records nothing.
        $this->assertSame([
            ['amount' => 500, 'tax' => [1900 => 46, 700 => 14], 'by' => 'admin'],
        ], $this->rows());
        $this->assertSame([], $this->gateway->recordedRefundListings());

        $this->deliver($echo);

        $this->assertSame([
            ['amount' => 500, 'tax' => [1900 => 46, 700 => 14], 'by' => 'admin'],
            ['amount' => 300, 'tax' => [1900 => 27, 700 => 8], 'by' => 'gateway'],
        ], $this->rows());
        $this->assertSame(800, $this->payment->refresh()->amount_refunded);
    }

    /**
     * Not healed, and pinned as such: a payment whose rows were already behind
     * (an outside refund from before this was fixed) stays behind after an admin
     * refund, because the admin's echo does not move the payment total and so
     * nothing asks the gateway for its list. The VAT is still right: the new
     * row is anchored to the payment total. `commerce:reconcile-refunds` is what
     * reports the missing row.
     */
    #[Test]
    public function an_admin_refund_on_a_payment_whose_rows_are_already_behind_does_not_backfill_them(): void
    {
        $this->gateway->recordOutsideRefund($this->payment, 500);
        $this->payment->update(['amount_refunded' => 500, 'status' => PaymentStatus::PartiallyRefunded]);

        $this->deliver($this->adminRefund(300));

        $this->assertSame([
            ['amount' => 300, 'tax' => [1900 => 73, 700 => 22], 'by' => 'admin'],
        ], $this->rows());
        $this->assertSame(800, $this->payment->refresh()->amount_refunded);
        $this->assertSame([], $this->gateway->recordedRefundListings());
    }

    /**
     * A refund lands at the gateway from outside the app. The gateway cuts its
     * event in the same moment, so the event carries the cumulative as it stood
     * right then, however late it is delivered.
     */
    private function outsideRefund(int $amount): Request
    {
        $this->gateway->recordOutsideRefund($this->payment, $amount);

        return $this->gateway->simulateWebhook($this->payment, PaymentStatus::Refunded);
    }

    /**
     * A refund from the admin. Returns the event the gateway cuts for it: the echo.
     */
    private function adminRefund(int $amount): Request
    {
        ($this->refundOrder)(order: $this->order, actor: RefundActor::admin('7', 'Jelte'), amount: $amount);

        return $this->gateway->simulateWebhook($this->payment, PaymentStatus::Refunded);
    }

    private function deliver(Request $event): void
    {
        ($this->process)('fake', $event);
    }

    /**
     * The order's refund rows, oldest first.
     *
     * @return list<array{amount: int, tax: array<int, int>, by: string}>
     */
    private function rows(): array
    {
        return $this->order->refunds()->orderBy('id')->get()
            ->map(fn (Refund $refund): array => [
                'amount' => $refund->amount,
                'tax' => $this->taxByRate($refund),
                'by' => $refund->actor_source->value,
            ])
            ->all();
    }

    /** @return array<int, int> rate in basis points => reversed tax in cents */
    private function taxByRate(Refund $refund): array
    {
        $tax = [];

        foreach ($refund->taxSummary() as $line) {
            $tax[$line->rateBps] = $line->tax;
        }

        return $tax;
    }

    /** @return array<int, int> reversed tax per rate, summed over every row */
    private function reversedTax(): array
    {
        $tax = [];

        foreach ($this->order->refunds()->get() as $refund) {
            foreach ($refund->taxSummary() as $line) {
                $tax[$line->rateBps] = ($tax[$line->rateBps] ?? 0) + $line->tax;
            }
        }

        return $tax;
    }

    private function assertNoNegativeTaxLine(): void
    {
        foreach ($this->order->refunds()->get() as $refund) {
            foreach ($refund->taxSummary() as $line) {
                $this->assertGreaterThanOrEqual(0, $line->tax, "refund {$refund->gateway_refund_id} reverses negative tax");
                $this->assertGreaterThanOrEqual(0, $line->taxableBase, "refund {$refund->gateway_refund_id} reverses a negative base");
            }
        }
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
