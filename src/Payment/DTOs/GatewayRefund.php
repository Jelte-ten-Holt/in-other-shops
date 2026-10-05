<?php

declare(strict_types=1);

namespace InOtherShops\Payment\DTOs;

/**
 * One refund as the gateway itself records it: its id there and the amount it
 * returned, in cents.
 */
final readonly class GatewayRefund
{
    public function __construct(
        public string $id,
        public int $amount,
    ) {}
}
