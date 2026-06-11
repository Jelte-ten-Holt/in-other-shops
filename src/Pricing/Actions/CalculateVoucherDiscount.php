<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Exceptions\VoucherNotFoundException;
use InOtherShops\Pricing\Models\Voucher;

/**
 * Pure calculation — validates the voucher and returns the discount amount.
 * Does not record usage. Safe to call repeatedly (cart total displays,
 * checkout review screens, etc.).
 *
 * For order commit, use {@see ApplyVoucher} which locks the row and
 * increments `times_used` atomically.
 */
final class CalculateVoucherDiscount
{
    public function __invoke(int $subtotal, string $code, Currency $currency): int
    {
        $voucher = $this->findVoucher($code);

        $voucher->validateForUse($subtotal, $currency);

        return $voucher->calculateDiscount($subtotal);
    }

    private function findVoucher(string $code): Voucher
    {
        $voucher = Voucher::where('code', $code)->first();

        if ($voucher === null) {
            throw VoucherNotFoundException::forCode($code);
        }

        return $voucher;
    }
}
