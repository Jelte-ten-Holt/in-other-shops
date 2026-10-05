<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Support;

use Illuminate\Http\Request;

/**
 * Builds Stripe webhook requests carrying a real signature, so a test runs the
 * driver's verification instead of stubbing it, and loads the captured event
 * bodies under tests/Fixtures/Stripe.
 */
trait SignsStripeWebhooks
{
    private const string WEBHOOK_SECRET = 'whsec_test_secret_for_signature_computation';

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

    /**
     * A captured live event body, byte for byte (see tests/Fixtures/Stripe/README.md).
     */
    private function stripeFixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/Stripe/'.$name);
    }
}
