<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Actions\RecordRefundSettlement;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Payment\DTOs\Settlement;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RecordRefundSettlementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_writes_the_settlement_onto_the_refund_and_says_so(): void
    {
        $refund = Refund::factory()->create(['gateway_refund_id' => 're_1']);

        $wrote = app(RecordRefundSettlement::class)($refund, $this->settlement('txn_1'));

        $this->assertTrue($wrote);
        $stored = $refund->fresh();
        $this->assertSame('txn_1', $stored->settlement_reference);
        $this->assertSame(1425, $stored->settled_amount);
        $this->assertSame('EUR', $stored->settled_currency);
        $this->assertSame('0.9500000000', $stored->exchange_rate);
        $this->assertSame(0, $stored->fee);
    }

    #[Test]
    public function a_refund_that_already_carries_a_settlement_is_never_overwritten(): void
    {
        $refund = Refund::factory()->create(['gateway_refund_id' => 're_1']);
        app(RecordRefundSettlement::class)($refund, $this->settlement('txn_1'));
        $before = $refund->fresh()->getAttributes();

        $stale = Refund::query()->find($refund->getKey());
        $stale->settlement_reference = null;

        $this->assertFalse(app(RecordRefundSettlement::class)($stale, $this->settlement('txn_2')));
        $this->assertSame($before, $refund->fresh()->getAttributes());
    }

    private function settlement(string $reference): Settlement
    {
        return new Settlement(
            reference: $reference,
            settledAt: CarbonImmutable::parse('2026-12-10 09:30:00'),
            amount: 1425,
            currency: 'EUR',
            fee: 0,
            net: 1425,
            exchangeRate: '0.9500000000',
        );
    }
}
