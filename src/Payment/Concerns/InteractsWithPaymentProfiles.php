<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Concerns;

use InOtherShops\Payment\Models\PaymentProfile;
use InOtherShops\Payment\Payment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait InteractsWithPaymentProfiles
{
    public function paymentProfiles(): MorphMany
    {
        $model = Payment::paymentProfile();

        return $this->morphMany($model, 'profileable');
    }

    public function paymentProfileFor(string $gateway): ?PaymentProfile
    {
        return $this->paymentProfiles()->where('gateway', $gateway)->first();
    }
}
