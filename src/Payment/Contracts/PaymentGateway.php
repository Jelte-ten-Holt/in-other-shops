<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Contracts;

use InOtherShops\Payment\DTOs\PaymentSession;
use InOtherShops\Payment\DTOs\WebhookPayload;
use InOtherShops\Payment\Models\Payment;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function createSession(Payment $payment, string $returnUrl, string $cancelUrl, ?string $gatewayCustomerId = null): PaymentSession;

    /**
     * Retrieve a live session for an existing payment. Used when a buyer
     * revisits a payment page (reload, deep-link, tab restore) and the
     * frontend needs the current `clientSecret` / `redirectUrl` without
     * creating a new gateway session.
     *
     * Gateways whose session is inherently single-use should still return
     * the current reference — the caller decides whether the payment state
     * allows continuation.
     */
    public function retrieveSession(Payment $payment): PaymentSession;

    /**
     * Throw when the signature does not verify. Called before `parseWebhook`
     * so the parser can assume the payload is authentic.
     */
    public function verifyWebhookSignature(Request $request): void;

    public function parseWebhook(Request $request): WebhookPayload;

    public function refund(Payment $payment, ?int $amount = null): void;

    public function identifier(): string;

    public function customerDashboardUrl(string $gatewayCustomerId): ?string;

    public function paymentDashboardUrl(Payment $payment): ?string;
}
