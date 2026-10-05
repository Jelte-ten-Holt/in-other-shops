<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Listeners;

use InOtherShops\Commerce\Order\Actions\RecordRefund;
use InOtherShops\Commerce\Order\DTOs\RefundActor;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Payment\Events\PaymentRefunded;

/**
 * Brings an order's Refund rows in line with the gateway's own list of refunds
 * for the payment, whenever a refund event moves the payment's refunded total:
 * a refund made in the Stripe dashboard, or through the API by anyone but this
 * app. The admin path records its own row directly through RefundOrder.
 *
 * Every listed refund is offered to RecordRefund, oldest first. It is
 * idempotent on (gateway, gateway_refund_id), so a refund already recorded —
 * an admin's, or one a previous event brought in — is found and left alone:
 * its actor stays who issued it, and RefundRecorded doesn't double-fire.
 */
final class ReconcileRefundFromWebhook
{
    public function __construct(
        private readonly RecordRefund $recordRefund,
    ) {}

    public function handle(PaymentRefunded $event): void
    {
        $order = $event->payment->payable;

        if (! $order instanceof Order) {
            return;
        }

        foreach ($event->gatewayRefunds as $refund) {
            ($this->recordRefund)(
                order: $order,
                payment: $event->payment,
                gatewayRefundId: $refund->id,
                amount: $refund->amount,
                actor: RefundActor::gateway(),
            );
        }
    }
}
