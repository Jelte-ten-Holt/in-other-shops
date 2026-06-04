<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Commerce\Order\Actions\RecordRefund;
use InOtherShops\Commerce\Order\DTOs\RefundActor;
use InOtherShops\Commerce\Order\Events\RefundRecorded;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RecordRefundTest extends TestCase
{
    use RefreshDatabase;

    private RecordRefund $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->record = $this->app->make(RecordRefund::class);
    }

    #[Test]
    public function it_records_a_refund_with_the_reversed_per_bracket_tax(): void
    {
        Event::fake([RefundRecorded::class]);

        $order = $this->order();
        $payment = $this->paymentFor($order);

        $refund = ($this->record)(
            order: $order,
            payment: $payment,
            gatewayRefundId: 're_full',
            amount: 1760,
            cumulativeRefunded: 1760,
            actor: RefundActor::admin('7', 'Jelte'),
            reason: 'Customer changed mind',
        );

        $this->assertSame(1760, $refund->amount);
        $this->assertSame('re_full', $refund->gateway_refund_id);
        $this->assertSame('Customer changed mind', $refund->reason);
        $this->assertSame('7', $refund->actor_id);

        // Full refund reverses each bracket to exactly its charged tax.
        $tax = [];
        foreach ($refund->taxSummary() as $line) {
            $tax[$line->rateBps] = $line->tax;
        }
        $this->assertSame([1900 => 160, 700 => 50], $tax);

        Event::assertDispatchedTimes(RefundRecorded::class, 1);
    }

    #[Test]
    public function it_is_idempotent_on_the_gateway_refund_id_and_dispatches_once(): void
    {
        Event::fake([RefundRecorded::class]);

        $order = $this->order();
        $payment = $this->paymentFor($order);

        $first = ($this->record)($order, $payment, 're_x', 1000, 1000, RefundActor::admin('7'));
        $second = ($this->record)($order, $payment, 're_x', 1000, 1000, RefundActor::gateway());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $order->refunds()->count());
        Event::assertDispatchedTimes(RefundRecorded::class, 1);
    }

    #[Test]
    public function a_sequence_of_partial_refunds_reverses_the_charged_tax_exactly(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);

        $cum = 0;
        foreach ([587, 587, 586] as $i => $amount) {
            $cum += $amount;
            ($this->record)($order, $payment, "re_seq_{$i}", $amount, $cum, RefundActor::admin('7'));
        }

        $tax = [];
        foreach ($order->refunds()->get() as $refund) {
            foreach ($refund->taxSummary() as $line) {
                $tax[$line->rateBps] = ($tax[$line->rateBps] ?? 0) + $line->tax;
            }
        }

        $this->assertSame(160, $tax[1900]);
        $this->assertSame(50, $tax[700]);
        $this->assertSame(1760, $order->fresh()->refundedTotal());
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
