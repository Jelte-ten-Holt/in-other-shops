<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Events\VoucherApplied;
use InOtherShops\Pricing\Exceptions\VoucherNotFoundException;
use InOtherShops\Pricing\Models\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * Records a voucher use. Acquires a `SELECT ... FOR UPDATE` lock on the
 * voucher row before incrementing `times_used`, so concurrent applies serialise
 * against each other and the count stays accurate.
 *
 * By default it re-validates under that lock and throws if the voucher went
 * invalid since it was quoted (race-loss, expiry). Pass `alreadyValidated: true`
 * when the caller validated at quote time and wants the redemption honoured
 * regardless — see the parameter's note.
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
    /**
     * @param  bool  $alreadyValidated  Skip the re-check under the lock and honour
     *                                  a voucher the caller already validated. The
     *                                  window this covers is the microseconds
     *                                  between quoting a total and committing the
     *                                  order; refusing there fails a checkout the
     *                                  shopper has already been shown a price for,
     *                                  which costs more than the rare overshoot
     *                                  past `max_uses`. Usage is still incremented
     *                                  under the lock, so `times_used` records what
     *                                  really happened (101/100 reads as the
     *                                  overshoot it is) rather than hiding it. A
     *                                  voucher that does not exist at all still
     *                                  throws — that is not a race.
     */
    public function __invoke(int $subtotal, string $code, Currency $currency, bool $alreadyValidated = false): Voucher
    {
        $voucher = DB::transaction(
            fn (): Voucher => $this->apply($subtotal, $code, $currency, $alreadyValidated),
        );

        VoucherApplied::dispatch($voucher);

        return $voucher;
    }

    private function apply(int $subtotal, string $code, Currency $currency, bool $alreadyValidated): Voucher
    {
        $voucher = $this->lockVoucher($code);

        if (! $alreadyValidated) {
            // Still inside the row lock — the guard runs on locked state, so
            // the TOCTOU semantics are unchanged by the extraction.
            $voucher->validateForUse($subtotal, $currency);
        }

        $voucher->incrementUsage();

        return $voucher;
    }

    private function lockVoucher(string $code): Voucher
    {
        $voucher = Voucher::where('code', $code)->lockForUpdate()->first();

        if ($voucher === null) {
            throw VoucherNotFoundException::forCode($code);
        }

        return $voucher;
    }
}
