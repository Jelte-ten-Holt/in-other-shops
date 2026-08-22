<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\CalculateVoucherDiscount;
use InOtherShops\Pricing\Exceptions\VoucherCurrencyMismatchException;
use InOtherShops\Pricing\Exceptions\VoucherInvalidException;
use InOtherShops\Pricing\Exceptions\VoucherMinimumNotMetException;
use InOtherShops\Pricing\Exceptions\VoucherNotFoundException;
use InOtherShops\Pricing\Enums\VoucherType;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->expectException(VoucherNotFoundException::class);
        $this->expectExceptionMessage('Voucher [MISSING] not found.');

        ($this->calculate)(5000, 'MISSING', Currency::EUR);
    }

    #[Test]
    public function it_throws_when_voucher_is_inactive_without_recording_usage(): void
    {
        Voucher::factory()->inactive()->create(['code' => 'OFF', 'times_used' => 0]);

        try {
            ($this->calculate)(5000, 'OFF', Currency::EUR);
            $this->fail('Expected VoucherInvalidException.');
        } catch (VoucherInvalidException $e) {
            $this->assertStringContainsString('Voucher [OFF] is no longer valid.', $e->getMessage());
        }

        $this->assertSame(0, Voucher::query()->where('code', 'OFF')->value('times_used'),
            'Calculate must never record usage — only ApplyVoucher does.');
    }

    #[Test]
    public function it_throws_when_voucher_is_expired_without_recording_usage(): void
    {
        Voucher::factory()->expired()->create(['code' => 'OLD', 'times_used' => 0]);

        try {
            ($this->calculate)(5000, 'OLD', Currency::EUR);
            $this->fail('Expected VoucherInvalidException.');
        } catch (VoucherInvalidException) {
            // expected
        }

        $this->assertSame(0, Voucher::query()->where('code', 'OLD')->value('times_used'));
    }

    #[Test]
    public function it_throws_when_voucher_is_at_max_uses_without_advancing_usage(): void
    {
        // Pre-pin times_used at the max value. A regression that incremented
        // before validating would push it to 2 and the test would fail.
        Voucher::factory()->withMaxUses(max: 1, used: 1)->create(['code' => 'BURNED']);

        try {
            ($this->calculate)(5000, 'BURNED', Currency::EUR);
            $this->fail('Expected VoucherInvalidException.');
        } catch (VoucherInvalidException) {
            // expected
        }

        $this->assertSame(1, Voucher::query()->where('code', 'BURNED')->value('times_used'));
    }

    #[Test]
    public function it_throws_when_subtotal_is_below_minimum(): void
    {
        Voucher::factory()->create(['code' => 'BIGORDER', 'minimum_order_amount' => 10000]);

        $this->expectException(VoucherMinimumNotMetException::class);
        $this->expectExceptionMessage('minimum amount');

        ($this->calculate)(5000, 'BIGORDER', Currency::EUR);
    }

    #[Test]
    public function it_throws_when_fixed_voucher_currency_does_not_match(): void
    {
        Voucher::factory()->create(['code' => 'EUROS', 'currency' => Currency::EUR]);

        $this->expectException(VoucherCurrencyMismatchException::class);
        $this->expectExceptionMessage('does not match order currency');

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

    #[Test]
    public function a_code_matches_regardless_of_case_or_stray_whitespace(): void
    {
        // A code is printed on a card and pasted out of an email. Normalizing
        // here rather than leaning on the column collation is what stops this
        // from passing under MySQL and failing under SQLite.
        Voucher::factory()->create([
            'code' => 'spring10',
            'type' => VoucherType::Fixed,
            'amount' => 500,
            'currency' => Currency::EUR,
        ]);

        $this->assertSame('SPRING10', Voucher::query()->value('code'),
            'The model must store the normalized code, not what was typed.');

        foreach (['SPRING10', 'spring10', ' Spring10 '] as $typed) {
            $this->assertSame(500, ($this->calculate)(5000, $typed, Currency::EUR),
                "Lookup must find the voucher for input [{$typed}].");
        }
    }
}
