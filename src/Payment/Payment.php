<?php

declare(strict_types=1);

namespace InOtherShops\Payment;

use InOtherShops\Payment\Models\Payment as PaymentModel;
use InOtherShops\Payment\Models\PaymentProfile;

final class Payment
{
    /** @return class-string<PaymentModel> */
    public static function payment(): string
    {
        return config('payment.models.payment', PaymentModel::class);
    }

    /** @return class-string<PaymentProfile> */
    public static function paymentProfile(): string
    {
        return config('payment.models.payment_profile', PaymentProfile::class);
    }
}
