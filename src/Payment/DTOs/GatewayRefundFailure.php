<?php

declare(strict_types=1);

namespace InOtherShops\Payment\DTOs;

/**
 * A refund the gateway accepted and later gave up on: the money never reached
 * the customer and is back on the shop's balance. Stripe does this when the
 * card is closed or expired, minutes or days after the refund was created
 * `succeeded`.
 */
final readonly class GatewayRefundFailure
{
    public function __construct(
        public string $id,
        public int $amount,
        public ?string $reason = null,
    ) {}
}
