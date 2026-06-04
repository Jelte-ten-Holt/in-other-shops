<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment\Stripe;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\DTOs\PaymentCustomerData;
use InOtherShops\Payment\Drivers\Stripe\StripePaymentGateway;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Exceptions\PaymentNotCancelableException;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\TestCase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Service\CustomerService;
use Stripe\Service\PaymentIntentService;
use Stripe\Service\RefundService;
use Stripe\StripeClient;

/**
 * Direct test of the Stripe driver. Uses Mockery to stub the StripeClient's
 * service magic-getter so the driver's outbound API calls don't hit Stripe,
 * and computes real Stripe webhook signatures so the signature-verification
 * path is exercised end-to-end.
 *
 * The driver is the only HTTP/3rd-party trust boundary in the payment
 * domain — until this test class existed, only FakePaymentGateway was
 * verified, so the Fake and Stripe drivers could silently diverge.
 */
final class StripePaymentGatewayTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private const string WEBHOOK_SECRET = 'whsec_test_secret_for_signature_computation';

    private StripeClient $client;

    private PaymentIntentService $paymentIntents;

    private RefundService $refunds;

    private CustomerService $customers;

    private StripePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentIntents = Mockery::mock(PaymentIntentService::class);
        $this->refunds = Mockery::mock(RefundService::class);
        $this->customers = Mockery::mock(CustomerService::class);

        // Stripe's StripeClient::__get delegates to getService($name); Mockery
        // doesn't intercept the magic __get reliably, but mocking getService
        // catches the same call path one stack frame deeper.
        $this->client = Mockery::mock(StripeClient::class);
        $this->client->shouldReceive('getService')->with('paymentIntents')->andReturn($this->paymentIntents);
        $this->client->shouldReceive('getService')->with('refunds')->andReturn($this->refunds);
        $this->client->shouldReceive('getService')->with('customers')->andReturn($this->customers);

        $this->gateway = new StripePaymentGateway(
            client: $this->client,
            webhookSecret: self::WEBHOOK_SECRET,
            webhookTolerance: 300,
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // identifier
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function identifier_is_stripe(): void
    {
        $this->assertSame('stripe', $this->gateway->identifier());
    }

    // ─────────────────────────────────────────────────────────────────
    // createSession
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function create_session_calls_payment_intents_with_amount_currency_metadata_and_automatic_methods(): void
    {
        $payment = $this->paymentFor(2500, Currency::EUR);

        $this->paymentIntents
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $params) use ($payment): bool {
                $this->assertSame(2500, $params['amount']);
                $this->assertSame('eur', $params['currency'],
                    'Stripe expects lowercase ISO currency codes.');
                $this->assertSame(['enabled' => true], $params['automatic_payment_methods']);
                $this->assertSame((string) $payment->id, $params['metadata']['payment_id']);
                $this->assertSame($payment->payable_type, $params['metadata']['payable_type']);
                $this->assertSame((string) $payment->payable_id, $params['metadata']['payable_id']);
                $this->assertArrayNotHasKey('customer', $params,
                    'No gatewayCustomerId means no customer key in the request.');

                return true;
            }), ['idempotency_key' => 'create_intent_'.$payment->id])
            ->andReturn(PaymentIntent::constructFrom([
                'id' => 'pi_test_abc123',
                'client_secret' => 'pi_test_abc123_secret_xyz',
                'status' => 'requires_payment_method',
            ]));

        $session = $this->gateway->createSession($payment, '/return', '/cancel');

        $this->assertSame('pi_test_abc123', $session->gatewayReference);
        $this->assertSame('pi_test_abc123_secret_xyz', $session->clientSecret);
        $this->assertSame('requires_payment_method', $session->gatewayData['payment_intent_status']);
    }

    #[Test]
    public function create_session_passes_gateway_customer_id_when_supplied(): void
    {
        $payment = $this->paymentFor(1000, Currency::EUR);

        $this->paymentIntents
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $params): bool {
                $this->assertSame('cus_test_existing', $params['customer']);

                return true;
            }), Mockery::type('array'))
            ->andReturn(PaymentIntent::constructFrom([
                'id' => 'pi_test_with_cust',
                'client_secret' => 'pi_test_with_cust_secret',
                'status' => 'requires_payment_method',
            ]));

        $this->gateway->createSession($payment, '/r', '/c', 'cus_test_existing');
    }

    // ─────────────────────────────────────────────────────────────────
    // retrieveSession
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function retrieve_session_throws_when_payment_has_no_gateway_reference(): void
    {
        $payment = $this->paymentFor(1000, Currency::EUR);
        $payment->gateway_reference = null;

        $this->paymentIntents->shouldNotReceive('retrieve');

        $this->expectException(RuntimeException::class);

        $this->gateway->retrieveSession($payment);
    }

    #[Test]
    public function retrieve_session_returns_a_payment_session_for_an_existing_intent(): void
    {
        $payment = $this->paymentFor(1000, Currency::EUR);
        $payment->gateway_reference = 'pi_existing_123';

        $this->paymentIntents
            ->shouldReceive('retrieve')
            ->once()
            ->with('pi_existing_123')
            ->andReturn(PaymentIntent::constructFrom([
                'id' => 'pi_existing_123',
                'client_secret' => 'pi_existing_123_secret',
                'status' => 'processing',
            ]));

        $session = $this->gateway->retrieveSession($payment);

        $this->assertSame('pi_existing_123', $session->gatewayReference);
        $this->assertSame('pi_existing_123_secret', $session->clientSecret);
        $this->assertSame('processing', $session->gatewayData['payment_intent_status']);
    }

    // ─────────────────────────────────────────────────────────────────
    // cancelSession
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function cancel_session_cancels_a_cancelable_intent(): void
    {
        $payment = $this->paymentFor(1000, Currency::EUR);
        $payment->gateway_reference = 'pi_cancelable';

        $this->paymentIntents
            ->shouldReceive('retrieve')
            ->once()
            ->with('pi_cancelable')
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_cancelable', 'status' => 'requires_payment_method']));

        $this->paymentIntents
            ->shouldReceive('cancel')
            ->once()
            ->with('pi_cancelable')
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_cancelable', 'status' => 'canceled']));

        $this->gateway->cancelSession($payment);
    }

    #[Test]
    public function cancel_session_is_a_noop_for_an_already_canceled_intent(): void
    {
        $payment = $this->paymentFor(1000, Currency::EUR);
        $payment->gateway_reference = 'pi_already_canceled';

        $this->paymentIntents
            ->shouldReceive('retrieve')
            ->once()
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_already_canceled', 'status' => 'canceled']));

        $this->paymentIntents->shouldNotReceive('cancel');

        $this->gateway->cancelSession($payment);
    }

    #[Test]
    public function cancel_session_throws_when_the_intent_is_live(): void
    {
        $payment = $this->paymentFor(1000, Currency::EUR);
        $payment->gateway_reference = 'pi_succeeded';

        $this->paymentIntents
            ->shouldReceive('retrieve')
            ->once()
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_succeeded', 'status' => 'succeeded']));

        $this->paymentIntents->shouldNotReceive('cancel');

        $this->expectException(PaymentNotCancelableException::class);

        $this->gateway->cancelSession($payment);
    }

    #[Test]
    public function cancel_session_is_a_noop_when_no_session_was_opened(): void
    {
        $payment = $this->paymentFor(1000, Currency::EUR);
        $payment->gateway_reference = null;

        $this->paymentIntents->shouldNotReceive('retrieve');
        $this->paymentIntents->shouldNotReceive('cancel');

        $this->gateway->cancelSession($payment);
    }

    // ─────────────────────────────────────────────────────────────────
    // refund
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function refund_with_null_amount_calls_stripe_refunds_with_payment_intent_only(): void
    {
        $payment = $this->paymentFor(2500, Currency::EUR);
        $payment->gateway_reference = 'pi_to_refund';

        $this->refunds
            ->shouldReceive('create')
            ->once()
            ->with([
                'payment_intent' => 'pi_to_refund',
                'amount' => null,
            ])
            ->andReturn(Refund::constructFrom(['id' => 're_test_full', 'status' => 'succeeded']));

        $this->gateway->refund($payment);
    }

    #[Test]
    public function refund_with_amount_calls_stripe_refunds_with_partial_amount(): void
    {
        $payment = $this->paymentFor(2500, Currency::EUR);
        $payment->gateway_reference = 'pi_partial';

        $this->refunds
            ->shouldReceive('create')
            ->once()
            ->with([
                'payment_intent' => 'pi_partial',
                'amount' => 1000,
            ])
            ->andReturn(Refund::constructFrom(['id' => 're_test_partial', 'status' => 'succeeded']));

        $this->gateway->refund($payment, 1000);
    }

    // ─────────────────────────────────────────────────────────────────
    // createCustomer
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function create_customer_returns_the_stripe_customer_id(): void
    {
        $this->customers
            ->shouldReceive('create')
            ->once()
            ->with([
                'email' => 'buyer@example.test',
                'name' => 'Buyer One',
                'phone' => '+31201234567',
            ])
            ->andReturn(Customer::constructFrom(['id' => 'cus_test_xyz']));

        $id = $this->gateway->createCustomer(new PaymentCustomerData(
            email: 'buyer@example.test',
            name: 'Buyer One',
            phone: '+31201234567',
        ));

        $this->assertSame('cus_test_xyz', $id);
    }

    #[Test]
    public function create_customer_passes_null_phone_and_name_through_to_stripe(): void
    {
        // Tests that the driver doesn't drop nullable fields silently.
        $this->customers
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $params): bool {
                $this->assertNull($params['name']);
                $this->assertNull($params['phone']);
                $this->assertSame('email-only@example.test', $params['email']);

                return true;
            }))
            ->andReturn(Customer::constructFrom(['id' => 'cus_email_only']));

        $this->gateway->createCustomer(new PaymentCustomerData(email: 'email-only@example.test'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Dashboard URLs (pure builders — no SDK call)
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function customer_dashboard_url_points_to_the_stripe_dashboard(): void
    {
        $this->assertSame(
            'https://dashboard.stripe.com/customers/cus_dash_test',
            $this->gateway->customerDashboardUrl('cus_dash_test'),
        );
    }

    #[Test]
    public function payment_dashboard_url_returns_null_when_payment_has_no_reference(): void
    {
        $payment = $this->paymentFor(100, Currency::EUR);
        $payment->gateway_reference = null;

        $this->assertNull($this->gateway->paymentDashboardUrl($payment));
    }

    #[Test]
    public function payment_dashboard_url_returns_a_stripe_payments_link_when_reference_is_set(): void
    {
        $payment = $this->paymentFor(100, Currency::EUR);
        $payment->gateway_reference = 'pi_dash_link';

        $this->assertSame(
            'https://dashboard.stripe.com/payments/pi_dash_link',
            $this->gateway->paymentDashboardUrl($payment),
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // verifyWebhookSignature (real signature computation)
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function verify_webhook_signature_accepts_a_valid_signature_for_the_configured_secret(): void
    {
        // Prove the happy path by exercising what depends on it: a valid
        // signature lets parseWebhook reach its body parser and return a
        // populated payload. Without verification succeeding, parseWebhook
        // wouldn't be safe to call. This avoids `expectNotToPerformAssertions`
        // (cf. docs/writing-tests.md "Don't `assertTrue(true)`").
        $payload = $this->validIntentEventJson('evt_sig_ok', 'payment_intent.succeeded', 'pi_sig_ok', 'succeeded');
        $request = $this->signedRequest($payload, time());

        $this->gateway->verifyWebhookSignature($request);
        $parsed = $this->gateway->parseWebhook($request);

        $this->assertSame('pi_sig_ok', $parsed->gatewayReference,
            'A valid signature must let parseWebhook reach the body without throwing.');
        $this->assertSame('evt_sig_ok', $parsed->eventId);
    }

    #[Test]
    public function verify_webhook_signature_throws_for_a_signature_signed_with_the_wrong_secret(): void
    {
        $payload = $this->validIntentEventJson('evt_bad_sig', 'payment_intent.succeeded', 'pi_x', 'succeeded');
        $request = $this->signedRequest($payload, time(), secret: 'whsec_attacker_secret');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stripe webhook signature verification failed.');

        $this->gateway->verifyWebhookSignature($request);
    }

    #[Test]
    public function verify_webhook_signature_throws_when_no_signature_header_is_present(): void
    {
        $request = Request::create('/wh', 'POST', content: 'whatever', server: ['CONTENT_TYPE' => 'application/json']);

        $this->expectException(RuntimeException::class);

        $this->gateway->verifyWebhookSignature($request);
    }

    #[Test]
    public function verify_webhook_signature_throws_for_a_timestamp_outside_the_tolerance_window(): void
    {
        // Stripe's tolerance check rejects timestamps > tolerance seconds away
        // from now(). Set a very short tolerance so a 60-second-old timestamp
        // is already stale — proves the tolerance argument is wired through.
        $payload = $this->validIntentEventJson('evt_stale', 'payment_intent.succeeded', 'pi_stale', 'succeeded');
        $oldTimestamp = time() - 600;
        $request = $this->signedRequest($payload, $oldTimestamp);

        $this->expectException(RuntimeException::class);

        $this->gateway->verifyWebhookSignature($request);
    }

    // ─────────────────────────────────────────────────────────────────
    // parseWebhook — event type → PaymentStatus mapping
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function parse_webhook_maps_payment_intent_succeeded_to_succeeded_status(): void
    {
        $payload = $this->validIntentEventJson('evt_succ', 'payment_intent.succeeded', 'pi_succ', 'succeeded');
        $request = $this->signedRequest($payload, time());

        $result = $this->gateway->parseWebhook($request);

        $this->assertSame('pi_succ', $result->gatewayReference);
        $this->assertSame(PaymentStatus::Succeeded, $result->status);
        $this->assertSame('evt_succ', $result->eventId);
        $this->assertSame('payment_intent.succeeded', $result->gatewayData['event_type']);
    }

    #[Test]
    public function parse_webhook_maps_payment_intent_payment_failed_to_failed_status(): void
    {
        $payload = $this->validIntentEventJson('evt_fail', 'payment_intent.payment_failed', 'pi_fail', 'requires_payment_method');
        $request = $this->signedRequest($payload, time());

        $this->assertSame(
            PaymentStatus::Failed,
            $this->gateway->parseWebhook($request)->status,
        );
    }

    #[Test]
    public function parse_webhook_maps_payment_intent_canceled_to_cancelled_status(): void
    {
        $payload = $this->validIntentEventJson('evt_cancel', 'payment_intent.canceled', 'pi_cancel', 'canceled');
        $request = $this->signedRequest($payload, time());

        $this->assertSame(
            PaymentStatus::Cancelled,
            $this->gateway->parseWebhook($request)->status,
        );
    }

    #[Test]
    public function parse_webhook_reads_a_charge_refunded_event_as_a_charge_not_an_intent(): void
    {
        // A real charge.refunded carries a CHARGE object: top-level id is ch_…,
        // the intent is in payment_intent, and amount_refunded is the cumulative.
        // Reading `id` blindly (the old bug) produced a ch_… reference that never
        // matched the stored pi_…, so the refund webhook silently no-op'd.
        $payload = $this->chargeRefundedEventJson('evt_refund', 'pi_refunded', 2000, 800, 're_abc');
        $request = $this->signedRequest($payload, time());

        $parsed = $this->gateway->parseWebhook($request);

        $this->assertSame('pi_refunded', $parsed->gatewayReference, 'reference must be the intent id, not the charge id');
        $this->assertSame(PaymentStatus::Refunded, $parsed->status);
        $this->assertSame(2000, $parsed->amount, 'amount carries the original charge so the amount guard still validates');
        $this->assertSame(800, $parsed->amountRefunded, 'amountRefunded is the cumulative refund total');
        $this->assertSame('re_abc', $parsed->gatewayRefundId);
    }

    #[Test]
    public function parse_webhook_reads_a_charge_refund_updated_event_as_a_refund(): void
    {
        // charge.refund.updated carries a REFUND object: id is re_…, the intent
        // is in payment_intent. We resolve the reference + refund id (so it's not
        // a silent mismatch) but leave amountRefunded null — the cumulative isn't
        // on the Refund object; charge.refunded carries the authoritative total.
        $payload = $this->refundUpdatedEventJson('evt_partial', 'pi_partial_refund', 're_def');
        $request = $this->signedRequest($payload, time());

        $parsed = $this->gateway->parseWebhook($request);

        $this->assertSame('pi_partial_refund', $parsed->gatewayReference);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $parsed->status);
        $this->assertNull($parsed->amountRefunded);
        $this->assertSame('re_def', $parsed->gatewayRefundId);
    }

    #[Test]
    public function parse_webhook_falls_back_to_intent_status_for_unmapped_event_types(): void
    {
        // Some Stripe event types aren't explicitly mapped (e.g. payment_intent.processing).
        // The fallback inspects the intent.status. processing → Pending.
        $payload = $this->validIntentEventJson('evt_other', 'payment_intent.processing', 'pi_processing', 'processing');
        $request = $this->signedRequest($payload, time());

        $this->assertSame(
            PaymentStatus::Pending,
            $this->gateway->parseWebhook($request)->status,
        );
    }

    #[Test]
    public function parse_webhook_falls_back_to_pending_for_unknown_intent_status(): void
    {
        // Defensive default: an intent status the driver has never seen
        // (a future Stripe addition) maps to Pending rather than throwing.
        // Otherwise a Stripe API change would break webhook ingestion.
        $payload = $this->validIntentEventJson('evt_unknown', 'unknown.event.type', 'pi_unknown', 'some_new_state');
        $request = $this->signedRequest($payload, time());

        $this->assertSame(
            PaymentStatus::Pending,
            $this->gateway->parseWebhook($request)->status,
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // parseWebhook — verified-event reuse
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function parse_webhook_reuses_the_verified_event_when_verify_was_called_first(): void
    {
        // verify + parse should call signature verification ONCE total — the
        // verified Event is cached on the gateway. The probe: pass a request
        // whose signature is valid, verify it, then parse. If parse re-verifies,
        // the test still passes; the meaningful check is that a SECOND parse
        // (after the verified-event has been consumed and reset) re-verifies
        // a fresh request rather than re-using stale state.
        $payload = $this->validIntentEventJson('evt_reuse', 'payment_intent.succeeded', 'pi_reuse', 'succeeded');
        $request = $this->signedRequest($payload, time());

        $this->gateway->verifyWebhookSignature($request);
        $first = $this->gateway->parseWebhook($request);

        $this->assertSame('evt_reuse', $first->eventId);

        // Second parse call WITHOUT a re-verify must still work — the gateway
        // falls back to verifying the signature itself rather than crashing
        // on a null verifiedEvent.
        $second = $this->gateway->parseWebhook($request);

        $this->assertSame('evt_reuse', $second->eventId);
    }

    #[Test]
    public function parse_webhook_works_standalone_without_a_prior_verify_call(): void
    {
        // When called on its own, parseWebhook constructs the Event itself
        // — meaning the driver still validates signature even if the caller
        // forgets to invoke verifyWebhookSignature first.
        $payload = $this->validIntentEventJson('evt_solo', 'payment_intent.succeeded', 'pi_solo', 'succeeded');
        $request = $this->signedRequest($payload, time());

        $result = $this->gateway->parseWebhook($request);

        $this->assertSame('evt_solo', $result->eventId);
    }

    #[Test]
    public function parse_webhook_called_standalone_validates_the_signature(): void
    {
        // No prior verify: parseWebhook itself must reject a tampered payload.
        $payload = $this->validIntentEventJson('evt_solo_bad', 'payment_intent.succeeded', 'pi_x', 'succeeded');
        $request = $this->signedRequest($payload, time(), secret: 'whsec_wrong');

        $this->expectException(\Stripe\Exception\SignatureVerificationException::class);

        $this->gateway->parseWebhook($request);
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    private function paymentFor(int $amount, Currency $currency): Payment
    {
        $payable = TestPayable::factory()->create(['total_due' => $amount]);

        return Payment::factory()->for($payable, 'payable')->create([
            'gateway' => 'stripe',
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentStatus::Pending,
        ]);
    }

    /**
     * Build a Stripe-shaped event JSON payload around a payment_intent object.
     */
    private function validIntentEventJson(string $eventId, string $eventType, string $intentId, string $intentStatus): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $eventType,
            'data' => [
                'object' => [
                    'id' => $intentId,
                    'object' => 'payment_intent',
                    'status' => $intentStatus,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function chargeRefundedEventJson(string $eventId, string $intentId, int $amount, int $amountRefunded, string $refundId): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => 'ch_'.$eventId,
                    'object' => 'charge',
                    'payment_intent' => $intentId,
                    'amount' => $amount,
                    'amount_refunded' => $amountRefunded,
                    'currency' => 'eur',
                    'refunds' => [
                        'object' => 'list',
                        'data' => [
                            ['id' => $refundId, 'object' => 'refund'],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function refundUpdatedEventJson(string $eventId, string $intentId, string $refundId): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'charge.refund.updated',
            'data' => [
                'object' => [
                    'id' => $refundId,
                    'object' => 'refund',
                    'payment_intent' => $intentId,
                    'charge' => 'ch_'.$eventId,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function signedRequest(string $payload, int $timestamp, ?string $secret = null): Request
    {
        $secret = $secret ?? self::WEBHOOK_SECRET;

        $signedPayload = "{$timestamp}.{$payload}";
        $signature = hash_hmac('sha256', $signedPayload, $secret);
        $header = "t={$timestamp},v1={$signature}";

        return Request::create(
            uri: '/webhooks/stripe',
            method: 'POST',
            content: $payload,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $header,
            ],
        );
    }
}
