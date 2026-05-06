<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Exceptions;

use InOtherShops\Currency\Enums\Currency;

final class VoucherCurrencyMismatchException extends PricingException
{
    public static function between(string $code, Currency $voucherCurrency, Currency $orderCurrency): self
    {
        return new self(
            "Voucher [{$code}] currency [{$voucherCurrency->value}] does not match order currency [{$orderCurrency->value}].",
        );
    }
}
