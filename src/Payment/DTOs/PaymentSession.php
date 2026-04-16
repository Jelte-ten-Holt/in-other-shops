<?php

declare(strict_types=1);

namespace InOtherShops\Payment\DTOs;

/**
 * Gateway response from session creation.
 *
 * One of `redirectUrl` or `clientSecret` is typically set — redirect for
 * hosted checkout flows, clientSecret for SDK-driven flows. Gateways with
 * neither (bank transfer, cash-on-delivery) leave both null.
 *
 * @param  array<string, mixed>  $gatewayData
 */
final readonly class PaymentSession
{
    /**
     * @param  array<string, mixed>  $gatewayData
     */
    public function __construct(
        public string $gatewayReference,
        public ?string $redirectUrl = null,
        public ?string $clientSecret = null,
        public array $gatewayData = [],
    ) {}
}
