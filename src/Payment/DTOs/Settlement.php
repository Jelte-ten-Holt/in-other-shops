<?php

declare(strict_types=1);

namespace InOtherShops\Payment\DTOs;

use Carbon\CarbonImmutable;

/**
 * What a payment or a refund did to the shop's balance at the gateway, as the
 * gateway records it (Stripe: the balance transaction). Amounts are magnitudes
 * in minor units of `currency`, the settlement currency; `exchangeRate` is a
 * decimal string, null when nothing was converted. `chargeReference` names the
 * charge a payment settled through, where the gateway has one.
 *
 * Nothing in the shop reads these; the bookkeeping read API exposes them.
 */
final readonly class Settlement
{
    public function __construct(
        public string $reference,
        public CarbonImmutable $settledAt,
        public int $amount,
        public string $currency,
        public int $fee,
        public int $net,
        public ?string $exchangeRate = null,
        public ?string $chargeReference = null,
    ) {}
}
