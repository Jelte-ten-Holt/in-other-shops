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
     * Cancel the gateway session/intent for a payment so it can no longer be
     * paid — used when abandoning an unpaid order (order-expiry), which is what
     * makes a late authorization on a released order impossible. Idempotent for
     * an already-cancelled (or never-created) intent. MUST throw
     * {@see \InOtherShops\Payment\Exceptions\PaymentNotCancelableException} when
     * the gateway refuses because the payment is live (already succeeded /
     * capturing), so the caller treats that as "payment in flight — do not
     * abandon the order."
     */
    public function cancelSession(Payment $payment): void;

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

    /**
     * Issue a refund and return the gateway's refund id (e.g. Stripe `re_…`).
     * The id is the idempotency anchor that lets an admin-initiated refund and
     * the gateway's echoing refund webhook converge on one record.
     */
    public function refund(Payment $payment, ?int $amount = null): string;

    public function identifier(): string;

    public function customerDashboardUrl(string $gatewayCustomerId): ?string;

    public function paymentDashboardUrl(Payment $payment): ?string;
}
