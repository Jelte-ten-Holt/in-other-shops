<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Actions;

use InOtherShops\Payment\DTOs\WebhookPayload;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Events\PaymentFailed;
use InOtherShops\Payment\Events\PaymentSucceeded;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\Models\WebhookEvent;
use InOtherShops\Payment\PaymentGatewayManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

final class ProcessPaymentWebhook
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
    ) {}

    public function __invoke(string $gatewayName, Request $request): ?Payment
    {
        $gateway = $this->gateways->gateway($gatewayName);

        $gateway->verifyWebhookSignature($request);

        $payload = $gateway->parseWebhook($request);

        if (! $this->recordIdempotency($gatewayName, $payload)) {
            return null;
        }

        $payment = $this->findPayment($gatewayName, $payload);

        if ($payment === null) {
            return null;
        }

        $changed = $this->updatePaymentStatus($payment, $payload);

        if ($changed) {
            $this->dispatchEvent($payment);
        }

        return $payment;
    }

    /**
     * Insert an idempotency row. Returns false when the delivery was already
     * processed (unique-constraint hit), true on first delivery or when the
     * gateway doesn't supply an event id.
     */
    private function recordIdempotency(string $gatewayName, WebhookPayload $payload): bool
    {
        if ($payload->eventId === null) {
            return true;
        }

        try {
            WebhookEvent::query()->create([
                'gateway' => $gatewayName,
                'event_id' => $payload->eventId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function findPayment(string $gatewayName, WebhookPayload $payload): ?Payment
    {
        return Payment::query()
            ->where('gateway', $gatewayName)
            ->where('gateway_reference', $payload->gatewayReference)
            ->first();
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
