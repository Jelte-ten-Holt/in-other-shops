<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Order\Events\RefundFailed;
use InOtherShops\Commerce\Order\Models\Refund;

/**
 * Marks a recorded refund as failed at the gateway. The row stays as the record
 * that the refund was made; `failed_at` takes it out of the order's refunded
 * total and its VAT reversal (every refund sum reads `notFailed()`), so the
 * next refund on the order reverses that VAT again.
 *
 * Idempotent: the write is conditional on `failed_at` still being null, so a
 * second call finds the row already failed, changes nothing and dispatches
 * nothing. Returns the row, or null when no refund with that gateway id was
 * ever recorded.
 */
final class RecordRefundFailure
{
    public function __invoke(string $gateway, string $gatewayRefundId, ?string $reason = null): ?Refund
    {
        $marked = Commerce::refund()::query()
            ->where('gateway', $gateway)
            ->where('gateway_refund_id', $gatewayRefundId)
            ->whereNull('failed_at')
            ->update(['failed_at' => now(), 'failure_reason' => $reason, 'updated_at' => now()]);

        /** @var Refund|null $refund */
        $refund = Commerce::refund()::query()
            ->where('gateway', $gateway)
            ->where('gateway_refund_id', $gatewayRefundId)
            ->first();

        if ($marked > 0 && $refund !== null) {
            RefundFailed::dispatch($refund);
        }

        return $refund;
    }
}
