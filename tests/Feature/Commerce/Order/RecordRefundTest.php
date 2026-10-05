<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InOtherShops\Commerce\Order\Actions\RecordRefund;
use InOtherShops\Commerce\Order\DTOs\RefundActor;
use InOtherShops\Commerce\Order\Enums\RefundActorSource;
use InOtherShops\Commerce\Order\Events\RefundRecorded;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\Refund;
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

        $first = ($this->record)($order, $payment, 're_x', 1000, RefundActor::admin('7'));
        $second = ($this->record)($order, $payment, 're_x', 1000, RefundActor::gateway());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $order->refunds()->count());
        Event::assertDispatchedTimes(RefundRecorded::class, 1);
    }

    #[Test]
    public function a_sequence_of_partial_refunds_reverses_the_charged_tax_exactly(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);

        foreach ([587, 587, 586] as $i => $amount) {
            ($this->record)($order, $payment, "re_seq_{$i}", $amount, RefundActor::admin('7'));
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

    /**
     * The admin's own call and the webhook listener can both be offered the same
     * refund and both miss the pre-check. The unique index refuses the second
     * insert; the loser returns the winner's row and announces nothing.
     */
    #[Test]
    public function a_refund_another_writer_recorded_between_the_check_and_the_insert_is_returned_not_duplicated(): void
    {
        Event::fake([RefundRecorded::class]);

        $order = $this->order();
        $payment = $this->paymentFor($order);

        // The other writer's row lands after this call's pre-check and outside
        // its own insert: on the first read of the order's refunds, which is
        // where the VAT reversal starts.
        $landed = false;
        DB::listen(function (QueryExecuted $query) use (&$landed, $order, $payment): void {
            if ($landed || ! str_contains($query->sql, 'refunds') || ! str_contains($query->sql, 'order_id')) {
                return;
            }

            $landed = true;

            DB::table('refunds')->insert([
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'gateway' => $payment->gateway,
                'gateway_refund_id' => 're_raced',
                'amount' => 1000,
                'actor_source' => RefundActorSource::Gateway->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $returned = ($this->record)($order, $payment, 're_raced', 1000, RefundActor::admin('7'));

        $this->assertTrue($landed);
        $this->assertSame(1, $order->refunds()->count());
        $this->assertSame(RefundActorSource::Gateway, $returned->actor_source, 'the row returned is the other writer\'s');
        Event::assertNotDispatched(RefundRecorded::class);
    }

    /**
     * The VAT anchor is the larger of "the rows once this refund is recorded"
     * and "the payment's refunded total". On 1760 charged {1900: 160, 700: 50},
     * 500 refunded reverses {46, 14}, 800 reverses {73, 22}, 1000 {91, 28}.
     */
    #[Test]
    public function the_vat_anchor_is_the_larger_of_the_rows_and_the_payment_total(): void
    {
        $order = $this->order();
        $payment = $this->paymentFor($order);

        // Payment ahead of the rows: 800 already refunded there, nothing
        // recorded here. The first row reverses the VAT of all 800.
        $payment->update(['amount_refunded' => 800]);
        $behind = ($this->record)($order, $payment, 're_behind', 300, RefundActor::admin('7'));

        $this->assertSame([1900 => 73, 700 => 22], $this->taxByRate($behind));

        // Rows ahead of the payment: 300 + 700 recorded against a payment total
        // still at 800. This row is anchored to 1000, not to 800.
        $ahead = ($this->record)($order, $payment, 're_ahead', 700, RefundActor::gateway());

        $this->assertSame([1900 => 18, 700 => 6], $this->taxByRate($ahead));
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
