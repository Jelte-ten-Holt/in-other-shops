<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Actions;

use Illuminate\Support\Facades\DB;
use InOtherShops\Payment\DTOs\Settlement;
use InOtherShops\Payment\Models\Payment;

/**
 * Writes a gateway's settlement onto a payment, once: the settlement fields and
 * `paid_at` in one update, so a reader sees a settled payment whole or not at
 * all. A payment that already carries a settlement is left alone; the gateway
 * does not rewrite a balance transaction. Returns whether it wrote.
 *
 * Takes the settlement as an argument: the gateway is asked before, outside
 * the row lock this holds.
 */
final class RecordPaymentSettlement
{
    public function __invoke(Payment $payment, Settlement $settlement): bool
    {
        return DB::transaction(function () use ($payment, $settlement): bool {
            /** @var Payment|null $locked */
            $locked = Payment::query()->lockForUpdate()->find($payment->getKey());

            if ($locked === null || $locked->settlement_reference !== null) {
                return false;
            }

            $locked->update([
                'charge_reference' => $settlement->chargeReference,
                'settlement_reference' => $settlement->reference,
                'paid_at' => $settlement->settledAt,
                'fee' => $settlement->fee,
                'net' => $settlement->net,
                'settled_amount' => $settlement->amount,
                'settled_currency' => $settlement->currency,
                'exchange_rate' => $settlement->exchangeRate,
            ]);

            $payment->setRawAttributes($locked->getAttributes(), sync: true);

            return true;
        });
    }
}
