<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Exceptions;

final class CountryNotShippableException extends ShippingException
{
    public static function forCountry(string $countryCode): self
    {
        return new self("No shipping zone is configured for country [{$countryCode}].");
    }
}
