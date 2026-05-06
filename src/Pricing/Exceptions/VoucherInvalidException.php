<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Exceptions;

final class VoucherInvalidException extends PricingException
{
    public static function expired(string $code): self
    {
        return new self("Voucher [{$code}] is no longer valid.");
    }
}
