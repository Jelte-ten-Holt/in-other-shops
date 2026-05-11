<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InOtherShops\Payment\DTOs\WebhookPayload;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Events\PaymentFailed;
use InOtherShops\Payment\Events\PaymentSucceeded;
use InOtherShops\Payment\Exceptions\PaymentAmountMismatchException;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\Models\WebhookEvent;
use InOtherShops\Payment\PaymentGatewayManager;

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

        return DB::transaction(function () use ($gatewayName, $payload): ?Payment {
            if (! $this->recordIdempotency($gatewayName, $payload)) {
                return null;
            }

            $payment = $this->findPayment($gatewayName, $payload);

            if ($payment === null) {
                return null;
            }

            $this->guardAmountMatches($payment, $payload);

            $changed = $this->updatePaymentStatus($payment, $payload);

            if ($changed) {
                $this->dispatchEvent($payment);
            }

            return $payment;
        });
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

    /**
     * Defense-in-depth: refuse to act on a webhook whose amount or currency
     * disagrees with the Payment row we created server-side. The signature has
     * already been verified, so a mismatch implies one of: a Stripe routing
     * bug, a confused-deputy in our own code (wrong gateway_reference linked),
     * or compromised webhook secrets. In all cases we'd rather fail loudly
     * than silently mark a payment "succeeded" for the wrong amount.
     *
     * The transaction wrapping `__invoke` rolls back the idempotency row on
     * throw, so the gateway's retry will hit this same guard again until the
     * underlying mismatch is resolved or the operator acks via the gateway
     * dashboard.
     */
    private function guardAmountMatches(Payment $payment, WebhookPayload $payload): void
    {
        if ($payload->amount !== null && $payload->amount !== $payment->amount) {
            throw PaymentAmountMismatchException::amount(
                expected: $payment->amount,
                received: $payload->amount,
            );
        }

        $expectedCurrency = $payment->currency?->value;

        if (
            $payload->currency !== null
            && $expectedCurrency !== null
            && strtolower($payload->currency) !== strtolower($expectedCurrency)
        ) {
            throw PaymentAmountMismatchException::currency(
                expected: strtolower($expectedCurrency),
                received: strtolower($payload->currency),
            );
        }
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
