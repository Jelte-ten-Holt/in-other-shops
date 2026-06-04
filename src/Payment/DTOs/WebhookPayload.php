<?php

declare(strict_types=1);

namespace InOtherShops\Payment\DTOs;

use InOtherShops\Payment\Enums\PaymentStatus;

final readonly class WebhookPayload
{
    /**
     * @param  array<string, mixed>  $gatewayData
     */
    public function __construct(
        public string $gatewayReference,
        public PaymentStatus $status,
        public ?string $eventId = null,
        public array $gatewayData = [],
        public ?int $amount = null,
        public ?string $currency = null,
        // Refund events only: the gateway's CUMULATIVE refunded amount on the
        // payment (absolute, not this event's delta) and the gateway refund id.
        // `amount` still carries the original charge amount so the amount guard
        // validates against the payment; `amountRefunded` is the separate refund
        // total. Both null on non-refund events.
        public ?int $amountRefunded = null,
        public ?string $gatewayRefundId = null,
    ) {}
}
