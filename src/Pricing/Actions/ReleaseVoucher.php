<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use Illuminate\Support\Facades\DB;
use InOtherShops\Pricing\Events\VoucherReleased;
use InOtherShops\Pricing\Models\Voucher;

/**
 * Gives a voucher use back — the counterpart of {@see ApplyVoucher}, and the
 * voucher-side mirror of releasing a stock reservation.
 *
 * An order that is never paid must not consume a voucher use any more than it
 * consumes the stock it reserved. Without this, an abandoned checkout burns a
 * use permanently: a 50-use campaign is eaten by shoppers who never paid, and a
 * single-use personal code locks its owner out of their own discount the moment
 * they close the payment tab.
 *
 * Floors at zero and takes the same `SELECT ... FOR UPDATE` lock as ApplyVoucher,
 * so a release racing an apply cannot drive the counter negative or lose an
 * increment. An unknown code is a no-op, not an error — the voucher may have
 * been deleted since the order was placed, and there is nothing to give back.
 */
final class ReleaseVoucher
{
    public function __invoke(string $code): ?Voucher
    {
        $voucher = DB::transaction(fn (): ?Voucher => $this->release($code));

        if ($voucher !== null) {
            VoucherReleased::dispatch($voucher);
        }

        return $voucher;
    }

    private function release(string $code): ?Voucher
    {
        $voucher = Voucher::where('code', Voucher::normalizeCode($code))
            ->lockForUpdate()
            ->first();

        if ($voucher === null || $voucher->times_used <= 0) {
            return null;
        }

        $voucher->decrement('times_used');

        return $voucher;
    }
}
