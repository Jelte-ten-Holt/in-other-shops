<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Events;

use InOtherShops\Payment\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class PaymentFailed
{
    use Dispatchable;

    public function __construct(
        public Payment $payment,
    ) {}
}
