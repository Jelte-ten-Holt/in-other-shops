<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Contracts;

use InOtherShops\Payment\Models\PaymentProfile;
use Illuminate\Database\Eloquent\Relations\MorphMany;

interface HasPaymentProfiles
{
    /**
     * @return MorphMany<PaymentProfile, $this>
     */
    public function paymentProfiles(): MorphMany;

    public function paymentProfileFor(string $gateway): ?PaymentProfile;
}
