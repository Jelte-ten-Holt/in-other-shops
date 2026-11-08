<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Listeners;

use Illuminate\Support\Facades\Log;
use InOtherShops\Commerce\Order\Actions\RecordRefundFailure;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Payment\Events\PaymentRefundFailed;

/**
 * Takes a failed refund out of its order's totals: marks the Refund row with
 * the gateway's id failed. Runs inside the webhook's transaction, under the
 * payment row lock, like {@see ReconcileRefundFromWebhook}.
 *
 * A failure for a refund with no row is logged, not thrown. It happens when the
 * failure is delivered before the refund event that would have recorded the
 * row, or when that event came after the failure and the gateway's list no
 * longer offered the refund. Throwing would have the gateway retry a delivery
 * that can never succeed; the payment total is already right, and
 * `commerce:reconcile-refunds` reports the payment if it drifts.
 */
final class RecordRefundFailureFromWebhook
{
    public function __construct(
        private readonly RecordRefundFailure $recordRefundFailure,
    ) {}

    public function handle(PaymentRefundFailed $event): void
    {
        if (! $event->payment->payable instanceof Order) {
            return;
        }

        $refund = ($this->recordRefundFailure)(
            gateway: $event->payment->gateway,
            gatewayRefundId: $event->refund->id,
            reason: $event->refund->reason,
        );

        if ($refund === null) {
            Log::warning('A refund failed at the gateway that this shop never recorded', [
                'payment_id' => $event->payment->getKey(),
                'order_id' => $event->payment->payable->getKey(),
                'gateway_refund_id' => $event->refund->id,
                'failed_amount' => $event->refund->amount,
            ]);
        }
    }
}
