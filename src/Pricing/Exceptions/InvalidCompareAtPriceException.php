<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Exceptions;

use DateTimeInterface;

final class InvalidCompareAtPriceException extends PricingException
{
    public static function notAbovePrice(int $compareAtAmount, int $amount): self
    {
        return new self(
            "Strikethrough price ({$compareAtAmount}) must be greater than the actual price ({$amount}).",
        );
    }

    public static function endDateWithoutAmount(): self
    {
        return new self(
            'A strikethrough end date (compareAtUntil) requires a strikethrough amount (compareAtAmount). '
            .'Set both together or clear both — an end date with no strikethrough is an orphan the expiry sweep never cleans.',
        );
    }

    public static function endDateInPast(DateTimeInterface $until): self
    {
        return new self(
            "Strikethrough end date ({$until->format(DATE_ATOM)}) must be in the future. "
            .'A past end date shows a strikethrough until the next hourly expiry sweep — up to an hour of false advertising.',
        );
    }
}
