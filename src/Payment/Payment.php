<?php

declare(strict_types=1);

namespace InOtherShops\Payment;

use InOtherShops\Payment\Models\Payment as PaymentModel;
use InOtherShops\Payment\Models\PaymentProfile;

final class Payment
{
    public static function payment(): PaymentModel
    {
        $class = config('payment.models.payment', PaymentModel::class);

        return new $class;
    }

    public static function paymentProfile(): PaymentProfile
    {
        $class = config('payment.models.payment_profile', PaymentProfile::class);

        return new $class;
    }
}
