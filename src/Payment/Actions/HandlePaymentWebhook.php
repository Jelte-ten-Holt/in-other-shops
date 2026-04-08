<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Actions;

use InOtherShops\Payment\Contracts\PaymentGateway;
use InOtherShops\Payment\DTOs\WebhookPayload;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Events\PaymentFailed;
use InOtherShops\Payment\Events\PaymentSucceeded;
use InOtherShops\Payment\Models\Payment;
use Illuminate\Http\Request;

final class HandlePaymentWebhook
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    public function __invoke(Request $request): ?Payment
    {
        $payload = $this->parseWebhook($request);

        $payment = $this->findPayment($payload);

        if ($payment === null) {
            return null;
        }

        $changed = $this->updatePaymentStatus($payment, $payload);

        if ($changed) {
            $this->dispatchEvent($payment);
        }

        return $payment;
    }

    private function parseWebhook(Request $request): WebhookPayload
    {
        return $this->gateway->parseWebhook($request);
    }

    private function findPayment(WebhookPayload $payload): ?Payment
    {
        return Payment::where('gateway_reference', $payload->gatewayReference)->first();
    }

    private function updatePaymentStatus(Payment $payment, WebhookPayload $payload): bool
    {
        if ($payment->status === $payload->status) {
            return false;
        }

        $payment->update([
            'status' => $payload->status,
            'gateway_data' => array_merge($payment->gateway_data ?? [], $payload->gatewayData),
        ]);

        return true;
    }

    private function dispatchEvent(Payment $payment): void
    {
        match ($payment->status) {
            PaymentStatus::Succeeded => PaymentSucceeded::dispatch($payment),
            PaymentStatus::Failed => PaymentFailed::dispatch($payment),
            default => null,
        };
    }
}
