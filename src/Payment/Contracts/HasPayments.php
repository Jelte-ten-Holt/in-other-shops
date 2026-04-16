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

    /**
     * Sum of net successful payments: `amount - amount_refunded` across
     * payments with status Succeeded or PartiallyRefunded.
     */
    public function totalPaid(): int;

    /**
     * The amount the payable owes, in the smallest currency subunit.
     * Implementations return the total from their own schema (e.g.
     * `$this->total` on Order). Required because the Payment domain
     * cannot infer the owing amount from payments alone.
     */
    public function getPaymentTotalDue(): int;

    /**
     * True when `totalPaid() >= getPaymentTotalDue()`. A single succeeded
     * payment that covers only part of the owed amount returns false.
     */
    public function isPaid(): bool;
}
