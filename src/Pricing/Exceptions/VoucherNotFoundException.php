<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Exceptions;

final class VoucherNotFoundException extends PricingException
{
    public static function forCode(string $code): self
    {
        return new self("Voucher [{$code}] not found.");
    }
}
