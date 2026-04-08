<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Contracts;

use InOtherShops\Payment\Models\Payment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

interface HasPayments
{
    /**
     * @return MorphMany<Payment, $this>
     */
    public function payments(): MorphMany;

    public function latestPayment(): ?Payment;

    public function isPaid(): bool;
}
