<?php

declare(strict_types=1);

namespace InOtherShops\Payment\DTOs;

final readonly class PaymentSession
{
    /**
     * Currently redirect-only. When client-side flows are needed (Stripe Elements,
     * Adyen Drop-in), add a PaymentFlowType enum and an optional clientSecret field.
     * The redirectUrl becomes nullable, and the payment page branches on flow type.
     *
     * @param  array<string, mixed>  $gatewayData
     */
    public function __construct(
        public string $redirectUrl,
        public string $gatewayReference,
        public array $gatewayData = [],
    ) {}
}
