<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Exceptions;

final class InvalidCompareAtPriceException extends PricingException
{
    public static function notAbovePrice(int $compareAtAmount, int $amount): self
    {
        return new self(
            "Strikethrough price ({$compareAtAmount}) must be greater than the actual price ({$amount}).",
        );
    }
}
