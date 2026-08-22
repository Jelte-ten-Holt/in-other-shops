<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Checkout;

use InOtherShops\Commerce\Checkout\DTOs\CheckoutQuote;
use InOtherShops\Commerce\Checkout\Support\VoucherSession;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class VoucherSessionTest extends TestCase
{
    #[Test]
    public function it_holds_the_code_and_nothing_else(): void
    {
        VoucherSession::remember('TENOFF');

        $this->assertSame('TENOFF', VoucherSession::code());
        $this->assertSame('TENOFF', session('checkout.voucher_code'));

        VoucherSession::forget();

        $this->assertNull(VoucherSession::code());
    }

    #[Test]
    public function sync_forgets_a_code_the_quote_dropped(): void
    {
        VoucherSession::remember('DEAD');

        VoucherSession::sync($this->quote(droppedVoucherCode: 'DEAD'));

        $this->assertNull(VoucherSession::code());
    }

    #[Test]
    public function sync_leaves_an_applied_code_alone(): void
    {
        VoucherSession::remember('TENOFF');

        VoucherSession::sync($this->quote(voucherCode: 'TENOFF'));

        $this->assertSame('TENOFF', VoucherSession::code());
    }

    #[Test]
    public function sync_does_not_forget_a_code_the_quote_never_saw(): void
    {
        // A quote made for another code (a stale tab, a race with apply) must
        // not clear the code the session holds now.
        VoucherSession::remember('FRESH');

        VoucherSession::sync($this->quote(droppedVoucherCode: 'STALE'));

        $this->assertSame('FRESH', VoucherSession::code());
    }

    private function quote(?string $voucherCode = null, ?string $droppedVoucherCode = null): CheckoutQuote
    {
        return new CheckoutQuote(
            subtotal: 5000,
            discount: 0,
            totalWithoutShipping: 5000,
            currency: Currency::EUR,
            voucherCode: $voucherCode,
            droppedVoucherCode: $droppedVoucherCode,
            requiresShipping: false,
            canShip: true,
            methodQuotes: [],
        );
    }
}
