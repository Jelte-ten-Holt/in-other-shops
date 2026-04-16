<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Drivers\Stripe;

use InOtherShops\Payment\Contracts\ManagesCustomers;
use InOtherShops\Payment\Contracts\PaymentGateway;
use InOtherShops\Payment\DTOs\PaymentCustomerData;
use InOtherShops\Payment\DTOs\PaymentSession;
use InOtherShops\Payment\DTOs\WebhookPayload;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use Illuminate\Http\Request;
use RuntimeException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe driver using Payment Intents. Returns a `clientSecret` via
 * {@see PaymentSession} for SDK-driven confirmation on the frontend
 * (Stripe Elements, Payment Element).
 *
 * Shipped only when `stripe/stripe-php` is installed — see
 * {@see StripeGatewayServiceProvider} for the gated registration.
 */
final class StripePaymentGateway implements ManagesCustomers, PaymentGateway
{
    public function __construct(
        private readonly StripeClient $client,
        private readonly string $webhookSecret,
    ) {}

    public function identifier(): string
    {
        return 'stripe';
    }

    public function createSession(Payment $payment, string $returnUrl, string $cancelUrl, ?string $gatewayCustomerId = null): PaymentSession
    {
        $intent = $this->client->paymentIntents->create([
            'amount' => $payment->amount,
            'currency' => strtolower($payment->currency->value),
            'customer' => $gatewayCustomerId,
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => [
                'payment_id' => (string) $payment->id,
                'payable_type' => $payment->payable_type ?? '',
                'payable_id' => (string) ($payment->payable_id ?? ''),
            ],
        ]);

        return new PaymentSession(
            gatewayReference: $intent->id,
            clientSecret: $intent->client_secret,
            gatewayData: ['payment_intent_status' => $intent->status],
        );
    }

    public function verifyWebhookSignature(Request $request): void
    {
        try {
            Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $this->webhookSecret,
            );
        } catch (SignatureVerificationException $e) {
            throw new RuntimeException('Stripe webhook signature verification failed.', previous: $e);
        }
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $event = Webhook::constructEvent(
            $request->getContent(),
            $request->header('Stripe-Signature', ''),
            $this->webhookSecret,
        );

        /** @var PaymentIntent $intent */
        $intent = $event->data->object;

        return new WebhookPayload(
            gatewayReference: $intent->id,
            status: $this->mapStatus($intent->status, $event->type),
            eventId: $event->id,
            gatewayData: [
                'event_type' => $event->type,
                'intent_status' => $intent->status,
            ],
        );
    }

    public function refund(Payment $payment, ?int $amount = null): void
    {
        $this->client->refunds->create([
            'payment_intent' => $payment->gateway_reference,
            'amount' => $amount,
        ]);
    }

    public function createCustomer(PaymentCustomerData $data): string
    {
        $customer = $this->client->customers->create([
            'email' => $data->email,
            'name' => $data->name,
            'phone' => $data->phone,
        ]);

        return $customer->id;
    }

    public function customerDashboardUrl(string $gatewayCustomerId): ?string
    {
        return "https://dashboard.stripe.com/customers/{$gatewayCustomerId}";
    }

    public function paymentDashboardUrl(Payment $payment): ?string
    {
        if ($payment->gateway_reference === null) {
            return null;
        }

        return "https://dashboard.stripe.com/payments/{$payment->gateway_reference}";
    }

    private function mapStatus(string $intentStatus, string $eventType): PaymentStatus
    {
        return match ($eventType) {
            'payment_intent.succeeded' => PaymentStatus::Succeeded,
            'payment_intent.payment_failed' => PaymentStatus::Failed,
            'payment_intent.canceled' => PaymentStatus::Cancelled,
            'charge.refunded' => PaymentStatus::Refunded,
            'charge.refund.updated' => PaymentStatus::PartiallyRefunded,
            default => match ($intentStatus) {
                'succeeded' => PaymentStatus::Succeeded,
                'canceled' => PaymentStatus::Cancelled,
                'requires_payment_method', 'requires_action', 'requires_confirmation', 'processing' => PaymentStatus::Pending,
                default => PaymentStatus::Pending,
            },
        };
    }
}
