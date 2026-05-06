<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Enums\VoucherType;
use InOtherShops\Pricing\Exceptions\VoucherCurrencyMismatchException;
use InOtherShops\Pricing\Exceptions\VoucherInvalidException;
use InOtherShops\Pricing\Exceptions\VoucherMinimumNotMetException;
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

        $this->validateVoucher($voucher, $subtotal, $currency);

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

    private function validateVoucher(Voucher $voucher, int $subtotal, Currency $currency): void
    {
        if (! $voucher->isValid()) {
            throw VoucherInvalidException::expired($voucher->code);
        }

        if (! $voucher->meetsMinimumOrder($subtotal)) {
            throw VoucherMinimumNotMetException::forCode($voucher->code);
        }

        if ($voucher->type === VoucherType::Fixed && $voucher->currency !== null && $voucher->currency !== $currency) {
            throw VoucherCurrencyMismatchException::between($voucher->code, $voucher->currency, $currency);
        }
    }
}
