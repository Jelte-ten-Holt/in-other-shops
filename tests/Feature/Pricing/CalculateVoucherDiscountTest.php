<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\CalculateVoucherDiscount;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class CalculateVoucherDiscountTest extends TestCase
{
    use RefreshDatabase;

    private CalculateVoucherDiscount $calculate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculate = new CalculateVoucherDiscount;
    }

    #[Test]
    public function it_returns_discount_for_a_valid_fixed_voucher(): void
    {
        Voucher::factory()->create(['code' => 'TENOFF', 'amount' => 1000]);

        $discount = ($this->calculate)(5000, 'TENOFF', Currency::EUR);

        $this->assertSame(1000, $discount);
    }

    #[Test]
    public function it_does_not_increment_usage(): void
    {
        $voucher = Voucher::factory()->create(['code' => 'READONLY']);

        ($this->calculate)(5000, 'READONLY', Currency::EUR);
        ($this->calculate)(5000, 'READONLY', Currency::EUR);

        $this->assertSame(0, $voucher->fresh()->times_used,
            'Calculation must not record usage — only ApplyVoucher does.');
    }

    #[Test]
    public function it_throws_when_voucher_does_not_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Voucher not found.');

        ($this->calculate)(5000, 'MISSING', Currency::EUR);
    }

    #[Test]
    public function it_throws_when_voucher_is_inactive(): void
    {
        Voucher::factory()->inactive()->create(['code' => 'OFF']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Voucher is no longer valid.');

        ($this->calculate)(5000, 'OFF', Currency::EUR);
    }

    #[Test]
    public function it_throws_when_voucher_is_expired(): void
    {
        Voucher::factory()->expired()->create(['code' => 'OLD']);

        $this->expectException(InvalidArgumentException::class);

        ($this->calculate)(5000, 'OLD', Currency::EUR);
    }

    #[Test]
    public function it_throws_when_voucher_is_at_max_uses(): void
    {
        Voucher::factory()->withMaxUses(max: 1, used: 1)->create(['code' => 'BURNED']);

        $this->expectException(InvalidArgumentException::class);

        ($this->calculate)(5000, 'BURNED', Currency::EUR);
    }

    #[Test]
    public function it_throws_when_subtotal_is_below_minimum(): void
    {
        Voucher::factory()->create(['code' => 'BIGORDER', 'minimum_order_amount' => 10000]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minimum amount');

        ($this->calculate)(5000, 'BIGORDER', Currency::EUR);
    }

    #[Test]
    public function it_throws_when_fixed_voucher_currency_does_not_match(): void
    {
        Voucher::factory()->create(['code' => 'EUROS', 'currency' => Currency::EUR]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('currency does not match');

        ($this->calculate)(5000, 'EUROS', Currency::USD);
    }

    #[Test]
    public function percentage_voucher_ignores_currency(): void
    {
        Voucher::factory()->percentage(10)->create(['code' => 'PCT']);

        $eur = ($this->calculate)(5000, 'PCT', Currency::EUR);
        $usd = ($this->calculate)(5000, 'PCT', Currency::USD);

        $this->assertSame(500, $eur);
        $this->assertSame(500, $usd);
    }
}
