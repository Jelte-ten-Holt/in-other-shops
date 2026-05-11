<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Exceptions;

final class PaymentAmountMismatchException extends PaymentException
{
    public static function amount(int $expected, int $received): self
    {
        return new self("Webhook payload amount [{$received}] does not match payment amount [{$expected}].");
    }

    public static function currency(string $expected, string $received): self
    {
        return new self("Webhook payload currency [{$received}] does not match payment currency [{$expected}].");
    }
}
