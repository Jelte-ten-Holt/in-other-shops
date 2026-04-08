<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Enums\VoucherType;
use InOtherShops\Pricing\Models\Voucher;
use InvalidArgumentException;

final class ApplyVoucher
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
