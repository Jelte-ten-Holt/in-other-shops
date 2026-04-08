<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Concerns;

use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment as PaymentModel;
use InOtherShops\Payment\Payment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait InteractsWithPayments
{
    public function payments(): MorphMany
    {
        $model = Payment::payment();

        return $this->morphMany($model::class, 'payable');
    }

    public function latestPayment(): ?PaymentModel
    {
        return $this->payments()->latest()->first();
    }

    public function isPaid(): bool
    {
        return $this->payments()->where('status', PaymentStatus::Succeeded)->exists();
    }
}
