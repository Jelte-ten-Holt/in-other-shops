<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Payment\Actions\RecordPaymentSettlement;
use InOtherShops\Payment\DTOs\Settlement;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RecordPaymentSettlementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_the_settlement_and_paid_at_together_and_says_so(): void
    {
        $payment = $this->payment();

        $wrote = app(RecordPaymentSettlement::class)($payment, $this->settlement('txn_1', '2026-11-20 18:42:07'));

        $this->assertTrue($wrote);
        $stored = $payment->fresh();
        $this->assertSame('txn_1', $stored->settlement_reference);
        $this->assertSame('ch_txn_1', $stored->charge_reference);
        $this->assertSame('2026-11-20 18:42:07', $stored->paid_at->utc()->format('Y-m-d H:i:s'));
        // The caller's instance is brought up to date too.
        $this->assertSame('txn_1', $payment->settlement_reference);
    }

    #[Test]
    public function a_payment_that_already_carries_a_settlement_is_never_overwritten(): void
    {
        $payment = $this->payment();
        app(RecordPaymentSettlement::class)($payment, $this->settlement('txn_1', '2026-11-20 18:42:07'));
        $before = $payment->fresh()->getAttributes();

        // A stale instance, as a second run racing the first would hold.
        $stale = Payment::query()->find($payment->getKey());
        $stale->settlement_reference = null;

        $wrote = app(RecordPaymentSettlement::class)($stale, $this->settlement('txn_2', '2026-11-21 09:00:00'));

        $this->assertFalse($wrote);
        $this->assertSame($before, $payment->fresh()->getAttributes());
    }

    private function payment(): Payment
    {
        return Payment::factory()->succeeded()
            ->for(TestPayable::factory()->create(['total_due' => 6400]), 'payable')
            ->create(['gateway' => 'fake', 'gateway_reference' => 'pi_1', 'amount' => 6400]);
    }

    private function settlement(string $reference, string $at): Settlement
    {
        return new Settlement(
            reference: $reference,
            settledAt: CarbonImmutable::parse($at),
            amount: 5888,
            currency: 'EUR',
            fee: 334,
            net: 5554,
            exchangeRate: '0.9200000000',
            chargeReference: 'ch_'.$reference,
        );
    }
}
