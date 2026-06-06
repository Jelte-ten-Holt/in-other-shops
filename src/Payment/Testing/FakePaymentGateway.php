<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Testing;

use InOtherShops\Payment\Contracts\ManagesCustomers;
use InOtherShops\Payment\Contracts\PaymentGateway;
use InOtherShops\Payment\DTOs\PaymentCustomerData;
use InOtherShops\Payment\DTOs\PaymentSession;
use InOtherShops\Payment\DTOs\WebhookPayload;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Exceptions\PaymentNotCancelableException;
use InOtherShops\Payment\Exceptions\RefundAmountExceededException;
use InOtherShops\Payment\Models\Payment;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * In-memory PaymentGateway for tests. Stands in for any real driver — the
 * `identifier` is configurable so it can be registered as 'stripe', 'mollie',
 * etc. and slot transparently into code paths that hardcode a gateway name.
 *
 * Records every call so tests can assert against `recordedSessions()`,
 * `recordedRefunds()`, and `recordedCustomers()`. Use `simulateWebhook()`
 * to forge a webhook request the gateway will parse without signature
 * verification noise.
 *
 * Not a real driver — never register from a non-test ServiceProvider.
 */
final class FakePaymentGateway implements ManagesCustomers, PaymentGateway
{
    /** @var array<int, array{payment: Payment, returnUrl: string, cancelUrl: string, gatewayCustomerId: ?string, reference: string}> */
    private array $sessions = [];

    /** @var array<int, array{payment: Payment, amount: ?int, id: string}> */
    private array $refunds = [];

    /**
     * Cumulative amount refunded per gateway reference, tracked by the gateway
     * itself — models Stripe capping a refund against the PaymentIntent's own
     * refunded total, independent of the local payment row.
     *
     * @var array<string, int>
     */
    private array $refundedByReference = [];

    /** @var array<int, PaymentCustomerData> */
    private array $customers = [];

    /** @var list<string> gateway references cancelled via cancelSession() */
    private array $cancellations = [];

    /** @var list<string> gateway references forced "live" so cancelSession() throws */
    private array $liveReferences = [];

    private int $sessionCounter = 0;

    private int $customerCounter = 0;

    private int $refundCounter = 0;

    public function __construct(
        private readonly string $identifier = 'fake',
    ) {}

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function createSession(Payment $payment, string $returnUrl, string $cancelUrl, ?string $gatewayCustomerId = null): PaymentSession
    {
        $reference = $this->nextReference();

        $this->sessions[] = [
            'payment' => $payment,
            'returnUrl' => $returnUrl,
            'cancelUrl' => $cancelUrl,
            'gatewayCustomerId' => $gatewayCustomerId,
            'reference' => $reference,
        ];

        return new PaymentSession(
            gatewayReference: $reference,
            clientSecret: $reference.'_secret',
            gatewayData: ['payment_intent_status' => 'requires_payment_method'],
        );
    }

    public function cancelSession(Payment $payment): void
    {
        if ($payment->gateway_reference === null) {
            return;
        }

        if (in_array($payment->gateway_reference, $this->liveReferences, true)) {
            throw PaymentNotCancelableException::inFlight($payment, 'fake: marked live');
        }

        if (! in_array($payment->gateway_reference, $this->cancellations, true)) {
            $this->cancellations[] = $payment->gateway_reference;
        }
    }

    public function retrieveSession(Payment $payment): PaymentSession
    {
        if ($payment->gateway_reference === null) {
            throw new RuntimeException("Cannot retrieve fake session: payment {$payment->id} has no gateway reference.");
        }

        return new PaymentSession(
            gatewayReference: $payment->gateway_reference,
            clientSecret: $payment->gateway_reference.'_secret',
            gatewayData: ['payment_intent_status' => 'requires_payment_method'],
        );
    }

    public function verifyWebhookSignature(Request $request): void
    {
        // No-op. Tests use simulateWebhook() to construct a request the parser accepts.
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        /** @var array<string, mixed> $body */
        $body = $request->json()->all();

        $reference = $body['gateway_reference'] ?? null;
        $statusValue = $body['status'] ?? null;

        if (! is_string($reference) || $reference === '') {
            throw new RuntimeException('Fake webhook payload missing gateway_reference.');
        }

        if (! is_string($statusValue) || PaymentStatus::tryFrom($statusValue) === null) {
            throw new RuntimeException('Fake webhook payload has invalid status: '.var_export($statusValue, true).'.');
        }

        $eventId = $body['event_id'] ?? null;
        $amount = $body['amount'] ?? null;
        $currency = $body['currency'] ?? null;
        $amountRefunded = $body['amount_refunded'] ?? null;
        $gatewayRefundId = $body['gateway_refund_id'] ?? null;

        return new WebhookPayload(
            gatewayReference: $reference,
            status: PaymentStatus::from($statusValue),
            eventId: is_string($eventId) ? $eventId : null,
            gatewayData: ['fake' => true],
            amount: is_int($amount) ? $amount : null,
            currency: is_string($currency) ? strtolower($currency) : null,
            amountRefunded: is_int($amountRefunded) ? $amountRefunded : null,
            gatewayRefundId: is_string($gatewayRefundId) ? $gatewayRefundId : null,
        );
    }

    public function refund(Payment $payment, ?int $amount = null): string
    {
        if ($payment->gateway_reference === null) {
            throw new RuntimeException("Cannot refund fake payment {$payment->id}: no gateway reference.");
        }

        // Cap against the gateway's OWN refunded total for this reference, not
        // the local payment row — exactly as Stripe does. This is the backstop
        // that makes a re-clicked refund safe after a lost local write (F34): the
        // local amount_refunded may read 0, but the gateway still rejects a second
        // refund of money it has already returned.
        $alreadyRefunded = $this->refundedByReference[$payment->gateway_reference] ?? 0;
        $maxRefundable = $payment->amount - $alreadyRefunded;
        $requested = $amount ?? $maxRefundable;

        if ($requested > $maxRefundable) {
            throw RefundAmountExceededException::exceeds($requested, $maxRefundable);
        }

        $this->refundCounter++;
        $refundId = 'fake_re_'.str_pad((string) $this->refundCounter, 6, '0', STR_PAD_LEFT);

        $this->refundedByReference[$payment->gateway_reference] = $alreadyRefunded + $requested;
        $this->refunds[] = ['payment' => $payment, 'amount' => $amount, 'id' => $refundId];

        return $refundId;
    }

    public function createCustomer(PaymentCustomerData $data): string
    {
        $this->customers[] = $data;

        $this->customerCounter++;

        return 'fake_cust_'.str_pad((string) $this->customerCounter, 6, '0', STR_PAD_LEFT);
    }

    public function customerDashboardUrl(string $gatewayCustomerId): ?string
    {
        return null;
    }

    public function paymentDashboardUrl(Payment $payment): ?string
    {
        return null;
    }

    /**
     * Build a webhook request the fake's parser will accept. Use as:
     *
     *     $request = $gateway->simulateWebhook($payment, PaymentStatus::Succeeded);
     *     ($processWebhook)($gateway->identifier(), $request);
     */
    public function simulateWebhook(
        Payment $payment,
        PaymentStatus $status,
        ?string $eventId = null,
        ?int $amountOverride = null,
        ?string $currencyOverride = null,
        ?int $amountRefunded = null,
        ?string $gatewayRefundId = null,
    ): Request {
        if ($payment->gateway_reference === null) {
            throw new RuntimeException("Cannot simulate webhook for payment {$payment->id}: no gateway reference.");
        }

        return Request::create(
            uri: '/webhooks/'.$this->identifier,
            method: 'POST',
            content: json_encode([
                'gateway_reference' => $payment->gateway_reference,
                'status' => $status->value,
                'event_id' => $eventId ?? 'fake_evt_'.uniqid(),
                'amount' => $amountOverride ?? $payment->amount,
                'currency' => strtolower($currencyOverride ?? $payment->currency?->value ?? ''),
                'amount_refunded' => $amountRefunded,
                'gateway_refund_id' => $gatewayRefundId,
            ], JSON_THROW_ON_ERROR),
            server: ['CONTENT_TYPE' => 'application/json'],
        );
    }

    /** @return array<int, array{payment: Payment, returnUrl: string, cancelUrl: string, gatewayCustomerId: ?string, reference: string}> */
    public function recordedSessions(): array
    {
        return $this->sessions;
    }

    /** @return array<int, array{payment: Payment, amount: ?int, id: string}> */
    public function recordedRefunds(): array
    {
        return $this->refunds;
    }

    /** @return array<int, PaymentCustomerData> */
    public function recordedCustomers(): array
    {
        return $this->customers;
    }

    /** @return list<string> gateway references cancelled via cancelSession() */
    public function recordedCancellations(): array
    {
        return $this->cancellations;
    }

    /**
     * Force a reference to be treated as live so cancelSession() throws
     * PaymentNotCancelableException — simulates an intent that already
     * succeeded / is processing when order-expiry tries to cancel it.
     */
    public function markSessionLive(string $gatewayReference): void
    {
        $this->liveReferences[] = $gatewayReference;
    }

    private function nextReference(): string
    {
        $this->sessionCounter++;

        return 'fake_pi_'.str_pad((string) $this->sessionCounter, 6, '0', STR_PAD_LEFT);
    }
}
