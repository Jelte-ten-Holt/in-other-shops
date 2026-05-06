<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Exceptions;

final class VoucherMinimumNotMetException extends PricingException
{
    public static function forCode(string $code): self
    {
        return new self("Order does not meet the minimum amount for voucher [{$code}].");
    }
}
