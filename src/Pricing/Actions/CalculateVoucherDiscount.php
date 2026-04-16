<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Enums\VoucherType;
use InOtherShops\Pricing\Models\Voucher;
use InvalidArgumentException;

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
            throw new InvalidArgumentException('Voucher not found.');
        }

        return $voucher;
    }

    private function validateVoucher(Voucher $voucher, int $subtotal, Currency $currency): void
    {
        if (! $voucher->isValid()) {
            throw new InvalidArgumentException('Voucher is no longer valid.');
        }

        if (! $voucher->meetsMinimumOrder($subtotal)) {
            throw new InvalidArgumentException('Order does not meet the minimum amount for this voucher.');
        }

        if ($voucher->type === VoucherType::Fixed && $voucher->currency !== null && $voucher->currency !== $currency) {
            throw new InvalidArgumentException('Voucher currency does not match order currency.');
        }
    }
}
