<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\DTOs;

use InOtherShops\Currency\Enums\Currency;

final readonly class ShippingZone
{
    /**
     * @param  array<int, string>  $countries  Uppercased ISO 3166-1 alpha-2 codes
     */
    public function __construct(
        public string $identifier,
        public string $name,
        public Currency $currency,
        public array $countries,
        public ?int $freeShippingThreshold,
        public int $sortOrder,
    ) {}

    public function includes(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), $this->countries, true);
    }

    public function qualifiesForFreeShipping(int $subtotalCents): bool
    {
        return $this->freeShippingThreshold !== null
            && $subtotalCents >= $this->freeShippingThreshold;
    }
}
