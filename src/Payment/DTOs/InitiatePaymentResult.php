<?php

declare(strict_types=1);

namespace InOtherShops\Payment\DTOs;

use InOtherShops\Payment\Models\Payment;

/**
 * Shape-neutral result of initiating a payment.
 *
 * Gateways supply whichever handoff mechanism they use:
 *
 * - **Redirect** (hosted checkout: Mollie, Adyen, Stripe Checkout): set
 *   `redirectUrl` to the provider page. `clientSecret` stays null.
 * - **Client-driven** (Stripe Payment Intents, Adyen Drop-in): set
 *   `clientSecret` for the frontend SDK. `redirectUrl` stays null.
 * - **Out-of-band** (bank transfer, cash-on-delivery): both stay null;
 *   the consumer renders its own confirmation UI.
 */
final readonly class InitiatePaymentResult
{
    public function __construct(
        public Payment $payment,
        public ?string $redirectUrl = null,
        public ?string $clientSecret = null,
    ) {}
}
