<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Exceptions;

use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;

final class PaymentNotRefundableException extends PaymentException
{
    public static function inStatus(Payment $payment, PaymentStatus $status): self
    {
        return new self("Payment [{$payment->id}] cannot be refunded in status [{$status->value}].");
    }
}
