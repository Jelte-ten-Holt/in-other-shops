<?php

declare(strict_types=1);

namespace InOtherShops\Payment\DTOs;

use InOtherShops\Payment\Models\Payment;

final readonly class InitiatePaymentResult
{
    public function __construct(
        public Payment $payment,
        public string $redirectUrl,
    ) {}
}
