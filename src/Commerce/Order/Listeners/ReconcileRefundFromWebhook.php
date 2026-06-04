<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Listeners;

use InOtherShops\Commerce\Order\Actions\RecordRefund;
use InOtherShops\Commerce\Order\DTOs\RefundActor;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Payment\Events\PaymentRefunded;

/**
 * Records the Refund row for a gateway-initiated refund (Stripe dashboard /
 * dispute / API), driven off the payment webhook. The admin path records its
 * own row directly through RefundOrder; this listener covers refunds that
 * originate outside the app.
 *
 * RecordRefund is idempotent on (gateway, gateway_refund_id), so a webhook
 * echoing an admin refund finds the existing row and no-ops — the actor stays
 * the admin who issued it, and RefundRecorded doesn't double-fire.
 */
final class ReconcileRefundFromWebhook
{
    public function __construct(
        private readonly RecordRefund $recordRefund,
    ) {}

    public function handle(PaymentRefunded $event): void
    {
        $payment = $event->payment;
        $order = $payment->payable;

        if (! $order instanceof Order || $event->gatewayRefundId === null || $event->refundAmount === null) {
            return;
        }

        ($this->recordRefund)(
            order: $order,
            payment: $payment,
            gatewayRefundId: $event->gatewayRefundId,
            amount: $event->refundAmount,
            cumulativeRefunded: $payment->amount_refunded,
            actor: RefundActor::gateway(),
        );
    }
}
