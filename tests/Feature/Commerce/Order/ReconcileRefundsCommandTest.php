<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The read-only refund tripwire: a payment on an order whose refunded total
 * differs from the sum of its Refund rows. It reports, and repairs nothing.
 */
final class ReconcileRefundsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_exits_clean_when_every_order_payment_matches_its_refund_rows(): void
    {
        $this->orderPayment(refunded: 0);

        $matching = $this->orderPayment(refunded: 800);
        $this->refundRow($matching, 500);
        $this->refundRow($matching, 300);

        // A second payment on the same order, with no refunds of its own: rows
        // are matched to a payment, not to its order.
        Payment::factory()->for($matching->payable, 'payable')->create([
            'gateway' => 'stripe',
            'amount' => 6350,
            'amount_refunded' => 0,
            'updated_at' => now()->subHour(),
        ]);

        // A payment for something that is not an order has no refund rows to
        // agree with, whatever its total says.
        Payment::factory()->for(TestPayable::factory()->create(['total_due' => 1760]), 'payable')->create([
            'amount' => 1760,
            'amount_refunded' => 500,
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('commerce:reconcile-refunds')->assertExitCode(0);
    }

    #[Test]
    public function it_exits_non_zero_and_lists_each_payment_that_disagrees_with_its_rows(): void
    {
        Log::spy();

        // A refund made outside the app that left no row.
        $behind = $this->orderPayment(refunded: 2200, reference: 'pi_rows_behind');

        // Rows ahead of the total: the event that would have closed the gap
        // never arrived.
        $ahead = $this->orderPayment(refunded: 500, reference: 'pi_rows_ahead');
        $this->refundRow($ahead, 500);
        $this->refundRow($ahead, 200);

        $this->orderPayment(refunded: 0, reference: 'pi_fine');

        $this->artisan('commerce:reconcile-refunds')
            ->expectsTable(
                ['payment', 'order', 'gateway_reference', 'refunded', 'recorded', 'delta'],
                [
                    [$behind->id, $behind->payable->order_number, 'pi_rows_behind', 2200, 0, 2200],
                    [$ahead->id, $ahead->payable->order_number, 'pi_rows_ahead', 500, 700, -200],
                ],
            )
            ->assertExitCode(1);

        Log::shouldHaveReceived('warning')->once()->with(
            'Refund reconciliation found payments that disagree with their refund rows',
            ['payment_ids' => [$behind->id, $ahead->id]],
        );
    }

    /**
     * Between two events of one run of outside refunds the rows are ahead of the
     * payment total by design; the next event closes the gap. A run landing in
     * that moment must not raise the alarm.
     */
    #[Test]
    public function a_payment_touched_within_the_last_fifteen_minutes_is_skipped_and_the_run_says_so(): void
    {
        $recent = $this->orderPayment(refunded: 500, touched: now()->subMinutes(14));
        $this->refundRow($recent, 500);
        $this->refundRow($recent, 200);

        $this->artisan('commerce:reconcile-refunds')
            ->expectsOutputToContain('1 payment(s) touched in the last 15 minutes were not checked')
            ->assertExitCode(0);

        $this->travel(2)->minutes();

        $this->artisan('commerce:reconcile-refunds')
            ->doesntExpectOutputToContain('were not checked')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_repairs_nothing_and_reports_the_same_on_a_second_run(): void
    {
        $payment = $this->orderPayment(refunded: 2200);
        $before = $payment->fresh()->getAttributes();

        $this->artisan('commerce:reconcile-refunds')->assertExitCode(1);
        $this->artisan('commerce:reconcile-refunds')->assertExitCode(1);

        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame(0, Refund::query()->count());
    }

    private function orderPayment(int $refunded, ?string $reference = null, ?\DateTimeInterface $touched = null): Payment
    {
        return Payment::factory()->for(Order::factory()->create(['total' => 6350]), 'payable')->create([
            'gateway' => 'stripe',
            'gateway_reference' => $reference ?? 'pi_'.uniqid(),
            'amount' => 6350,
            'amount_refunded' => $refunded,
            'currency' => Currency::EUR,
            'status' => $refunded > 0 ? PaymentStatus::PartiallyRefunded : PaymentStatus::Succeeded,
            'updated_at' => $touched ?? now()->subHour(),
        ]);
    }

    private function refundRow(Payment $payment, int $amount): Refund
    {
        return Refund::factory()->create([
            'order_id' => $payment->payable_id,
            'payment_id' => $payment->id,
            'gateway' => $payment->gateway,
            'amount' => $amount,
        ]);
    }
}
