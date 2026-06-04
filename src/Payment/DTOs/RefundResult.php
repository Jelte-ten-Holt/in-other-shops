<?php

declare(strict_types=1);

namespace InOtherShops\Payment\DTOs;

/**
 * The outcome of a gateway refund: the gateway's refund id (the idempotency
 * anchor), the amount this refund returned, and the payment's cumulative
 * refunded total after it. The orchestration layer (Commerce) uses these to
 * record the Refund row and reverse tax against the cumulative.
 */
final readonly class RefundResult
{
    public function __construct(
        public string $gatewayRefundId,
        public int $amount,
        public int $cumulativeRefunded,
    ) {}
}
