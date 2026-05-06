<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Exceptions;

final class RefundAmountExceededException extends PaymentException
{
    public static function exceeds(int $requested, int $maxRefundable): self
    {
        return new self("Refund amount [{$requested}] exceeds refundable amount [{$maxRefundable}].");
    }
}
