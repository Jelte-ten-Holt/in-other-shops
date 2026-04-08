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

    public function parseWebhook(Request $request): WebhookPayload;

    public function refund(Payment $payment, ?int $amount = null): void;

    public function identifier(): string;

    public function customerDashboardUrl(string $gatewayCustomerId): ?string;

    public function paymentDashboardUrl(Payment $payment): ?string;
}
