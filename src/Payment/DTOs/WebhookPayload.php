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
        public array $gatewayData = [],
    ) {}
}
