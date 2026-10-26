<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Exceptions\PaymentNotCancelableException;
use InOtherShops\Payment\PaymentGatewayManager;

/**
 * Closes every gateway session through which an order could still be paid, so
 * no late payment can land on it: before expiry cancels the order, and before
 * an admin records it as paid by other means. Calls the gateway — never hold a
 * row lock around it.
 *
 * Failed is included alongside Pending because a failed ATTEMPT is not a
 * terminal intent state: after a card decline the gateway parks the intent in
 * requires_payment_method, still live and still completable from the shopper's
 * open payment page.
 *
 * @throws PaymentNotCancelableException when an intent is live (succeeded or
 *         processing): the money may yet move, and the caller must not act on
 *         the order as unpaid.
 */
final class CancelOpenGatewaySessions
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
    ) {}

    public function __invoke(Order $order): void
    {
        $payments = $order->payments()
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])
            ->whereNotNull('gateway_reference')
            ->get();

        foreach ($payments as $payment) {
            $this->gateways->gateway($payment->gateway)->cancelSession($payment);
        }
    }
}
