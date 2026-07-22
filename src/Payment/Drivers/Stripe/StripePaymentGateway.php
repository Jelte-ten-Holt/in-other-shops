<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Drivers\Stripe;

use InOtherShops\Payment\Contracts\ManagesCustomers;
use InOtherShops\Payment\Contracts\PaymentGateway;
use InOtherShops\Payment\DTOs\PaymentCustomerData;
use InOtherShops\Payment\DTOs\PaymentSession;
use InOtherShops\Payment\DTOs\WebhookPayload;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Exceptions\PaymentNotCancelableException;
use InOtherShops\Payment\Models\Payment;
use Illuminate\Http\Request;
use RuntimeException;
use Stripe\Event;
use Stripe\Exception\InvalidRequestException;
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
    private ?Event $verifiedEvent = null;

    public function __construct(
        private readonly StripeClient $client,
        private readonly string $webhookSecret,
        private readonly int $webhookTolerance = 300,
    ) {}

    public function identifier(): string
    {
        return 'stripe';
    }

    public function createSession(Payment $payment, string $returnUrl, string $cancelUrl, ?string $gatewayCustomerId = null): PaymentSession
    {
        $params = [
            'amount' => $payment->amount,
            'currency' => strtolower($payment->currency->value),
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => [
                'payment_id' => (string) $payment->id,
                'payable_type' => $payment->payable_type ?? '',
                'payable_id' => (string) ($payment->payable_id ?? ''),
            ],
        ];

        if ($gatewayCustomerId !== null) {
            $params['customer'] = $gatewayCustomerId;
        }

        // Idempotency key = payment id, so a retried session-open (e.g. the
        // post-commit step ran twice) returns the SAME intent instead of
        // creating a duplicate charge (F1 hardening).
        $intent = $this->client->paymentIntents->create($params, [
            'idempotency_key' => 'create_intent_'.$payment->id,
        ]);

        return new PaymentSession(
            gatewayReference: $intent->id,
            clientSecret: $intent->client_secret,
            gatewayData: ['payment_intent_status' => $intent->status],
        );
    }

    public function cancelSession(Payment $payment): void
    {
        if ($payment->gateway_reference === null) {
            return; // no session was ever opened — nothing to cancel
        }

        $intent = $this->client->paymentIntents->retrieve($payment->gateway_reference);

        if ($intent->status === PaymentIntent::STATUS_CANCELED) {
            return; // already cancelled — idempotent no-op
        }

        // Succeeded or async-processing means the money may yet move; the order
        // must NOT be abandoned. Let the confirm path resolve it instead.
        if (in_array($intent->status, [PaymentIntent::STATUS_SUCCEEDED, PaymentIntent::STATUS_PROCESSING], true)) {
            throw PaymentNotCancelableException::inFlight($payment, "intent status: {$intent->status}");
        }

        try {
            $this->client->paymentIntents->cancel($payment->gateway_reference);
        } catch (InvalidRequestException $e) {
            // The intent raced to a non-cancelable state (typically succeeded)
            // between the retrieve above and this cancel. Stripe rejects the
            // cancel with an InvalidRequestException; surface it as the same
            // "money may yet move" signal the status check raises, so the caller
            // (order-expiry / cancel-and-replace) leaves the order for the
            // confirm path rather than 500-ing on a raw Stripe error (M11).
            throw PaymentNotCancelableException::inFlight($payment, $e->getMessage());
        }
    }

    public function retrieveSession(Payment $payment): PaymentSession
    {
        if ($payment->gateway_reference === null) {
            throw new RuntimeException("Cannot retrieve Stripe session: payment {$payment->id} has no gateway reference.");
        }

        $intent = $this->client->paymentIntents->retrieve($payment->gateway_reference);

        return new PaymentSession(
            gatewayReference: $intent->id,
            clientSecret: $intent->client_secret,
            gatewayData: ['payment_intent_status' => $intent->status],
        );
    }

    public function verifyWebhookSignature(Request $request): void
    {
        try {
            $this->verifiedEvent = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $this->webhookSecret,
                $this->webhookTolerance,
            );
        } catch (SignatureVerificationException $e) {
            throw new RuntimeException('Stripe webhook signature verification failed.', previous: $e);
        }
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $event = $this->verifiedEvent ?? Webhook::constructEvent(
            $request->getContent(),
            $request->header('Stripe-Signature', ''),
            $this->webhookSecret,
            $this->webhookTolerance,
        );

        $this->verifiedEvent = null;

        // Branch on the event type BEFORE reading variant fields. charge.* events
        // carry a Charge (charge.refunded) or a Refund (charge.refund.updated) —
        // NOT a PaymentIntent. Reading `id` off them as if it were an intent is
        // the F27 bug (a ch_…/re_… id that never matches the stored pi_…).
        if ($event->type === 'charge.refunded') {
            return $this->parseChargeRefunded($event);
        }

        if ($event->type === 'charge.refund.updated') {
            return $this->parseRefundUpdated($event);
        }

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
            amount: isset($intent->amount) && is_int($intent->amount) ? $intent->amount : null,
            currency: isset($intent->currency) && is_string($intent->currency) ? strtolower($intent->currency) : null,
        );
    }

    /**
     * charge.refunded — data.object is a Charge. The intent id is in
     * `payment_intent`; `amount` is the original charge (so the amount guard
     * still validates against the payment), `amount_refunded` is the CUMULATIVE
     * refund on the charge, and the latest refund id anchors the Refund record.
     */
    private function parseChargeRefunded(\Stripe\Event $event): WebhookPayload
    {
        /** @var \Stripe\Charge $charge */
        $charge = $event->data->object;

        return new WebhookPayload(
            gatewayReference: (string) $charge->payment_intent,
            status: $this->mapStatus('', $event->type),
            eventId: $event->id,
            gatewayData: ['event_type' => $event->type],
            amount: isset($charge->amount) && is_int($charge->amount) ? $charge->amount : null,
            currency: isset($charge->currency) && is_string($charge->currency) ? strtolower($charge->currency) : null,
            amountRefunded: isset($charge->amount_refunded) && is_int($charge->amount_refunded) ? $charge->amount_refunded : null,
            gatewayRefundId: $this->latestRefundId($charge),
        );
    }

    /**
     * charge.refund.updated — data.object is a Refund (async refund status
     * transitions). We resolve the intent reference + refund id so it's not a
     * silent no-op, but leave `amountRefunded` null: the cumulative isn't on the
     * Refund object, and charge.refunded already carries the authoritative total.
     */
    private function parseRefundUpdated(\Stripe\Event $event): WebhookPayload
    {
        /** @var \Stripe\Refund $refund */
        $refund = $event->data->object;

        return new WebhookPayload(
            gatewayReference: (string) $refund->payment_intent,
            status: $this->mapStatus('', $event->type),
            eventId: $event->id,
            gatewayData: ['event_type' => $event->type],
            gatewayRefundId: (string) $refund->id,
        );
    }

    private function latestRefundId(\Stripe\Charge $charge): ?string
    {
        $data = $charge->refunds->data ?? [];

        return isset($data[0]->id) ? (string) $data[0]->id : null;
    }

    public function refund(Payment $payment, ?int $amount = null): string
    {
        $refund = $this->client->refunds->create([
            'payment_intent' => $payment->gateway_reference,
            'amount' => $amount,
        ]);

        // The gateway refund id (re_…) is the idempotency anchor: it lets the
        // admin-initiated Refund row and the echoing charge.refunded webhook
        // converge on one record instead of double-counting.
        return $refund->id;
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
