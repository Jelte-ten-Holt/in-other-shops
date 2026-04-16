<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Concerns;

use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment as PaymentModel;
use InOtherShops\Payment\Payment;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

trait InteractsWithPayments
{
    public function payments(): MorphMany
    {
        $model = Payment::payment();

        return $this->morphMany($model, 'payable');
    }

    public function latestPayment(): ?PaymentModel
    {
        return $this->payments()->latest()->first();
    }

    public function totalPaid(): int
    {
        return (int) $this->payments()
            ->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded])
            ->sum(DB::raw('amount - amount_refunded'));
    }

    public function isPaid(): bool
    {
        return $this->totalPaid() >= $this->getPaymentTotalDue();
    }
}
