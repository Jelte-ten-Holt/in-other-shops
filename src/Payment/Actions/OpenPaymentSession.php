<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Actions;

use InOtherShops\Payment\DTOs\InitiatePaymentResult;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;

/**
 * Open the gateway session/intent for an already-persisted Pending payment and
 * record its gateway reference — the *pay* half of persist-then-pay (F1). This
 * is the step that makes the network call, so it MUST run OUTSIDE any open DB
 * transaction: if it fails, the Pending payment + order simply remain unpaid
 * (benign, cleaned up by order-expiry), rather than orphaning a live intent
 * against a rolled-back order.
 *
 * The gateway driver keys the intent on the payment id (idempotency), so a
 * retried call returns the same intent instead of creating a duplicate charge.
 */
final class OpenPaymentSession
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
    ) {}

    public function __invoke(
        Payment $payment,
        string $returnUrl,
        string $cancelUrl,
        ?string $gatewayCustomerId = null,
    ): InitiatePaymentResult {
        $gateway = $this->gateways->gateway($payment->gateway);

        $session = $gateway->createSession($payment, $returnUrl, $cancelUrl, $gatewayCustomerId);

        $payment->update([
            'gateway_reference' => $session->gatewayReference,
            'gateway_data' => $session->gatewayData,
        ]);

        return new InitiatePaymentResult(
            payment: $payment,
            redirectUrl: $session->redirectUrl,
            clientSecret: $session->clientSecret,
        );
    }
}
