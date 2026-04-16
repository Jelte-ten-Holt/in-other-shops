<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Enums\VoucherType;
use InOtherShops\Pricing\Events\VoucherApplied;
use InOtherShops\Pricing\Models\Voucher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records a voucher use. Acquires a `SELECT ... FOR UPDATE` lock on the
 * voucher row before re-validating and incrementing `times_used`, so
 * concurrent applies cannot exceed `max_uses`. Throws if the voucher
 * is invalid at apply time (race-loss, expiry, etc.).
 *
 * Call this at order-commit time, inside the same outer transaction as
 * the order-creation action (Phase E1) so a failed order rolls back the
 * usage increment too.
 *
 * For total calculation without recording usage, use
 * {@see CalculateVoucherDiscount}.
 */
final class ApplyVoucher
{
    public function __invoke(int $subtotal, string $code, Currency $currency): Voucher
    {
        $voucher = DB::transaction(
            fn (): Voucher => $this->apply($subtotal, $code, $currency),
        );

        VoucherApplied::dispatch($voucher);

        return $voucher;
    }

    private function apply(int $subtotal, string $code, Currency $currency): Voucher
    {
        $voucher = $this->lockVoucher($code);

        $this->validateVoucher($voucher, $subtotal, $currency);

        $voucher->incrementUsage();

        return $voucher;
    }

    private function lockVoucher(string $code): Voucher
    {
        $voucher = Voucher::where('code', $code)->lockForUpdate()->first();

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
