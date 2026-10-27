<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use Illuminate\Support\Facades\DB;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Payment\DTOs\Settlement;

/**
 * Writes a gateway's settlement onto a refund, once, the way
 * RecordPaymentSettlement does for a payment. A refund has no `paid_at`: its
 * own `created_at` is when it was made. Returns whether it wrote.
 */
final class RecordRefundSettlement
{
    public function __invoke(Refund $refund, Settlement $settlement): bool
    {
        return DB::transaction(function () use ($refund, $settlement): bool {
            /** @var Refund|null $locked */
            $locked = Commerce::refund()::query()->lockForUpdate()->find($refund->getKey());

            if ($locked === null || $locked->settlement_reference !== null) {
                return false;
            }

            $locked->update([
                'settlement_reference' => $settlement->reference,
                'fee' => $settlement->fee,
                'settled_amount' => $settlement->amount,
                'settled_currency' => $settlement->currency,
                'exchange_rate' => $settlement->exchangeRate,
            ]);

            $refund->setRawAttributes($locked->getAttributes(), sync: true);

            return true;
        });
    }
}
