<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\DTOs;

final readonly class ShippingMethod
{
    /**
     * @param  array<string, int>  $rates  zone identifier => cost in cents
     */
    public function __construct(
        public string $identifier,
        public string $name,
        public int $sortOrder,
        public bool $isActive,
        public array $rates,
    ) {}

    public function isAvailableInZone(string $zoneIdentifier): bool
    {
        return array_key_exists($zoneIdentifier, $this->rates);
    }

    public function rateForZone(string $zoneIdentifier): ?int
    {
        return $this->rates[$zoneIdentifier] ?? null;
    }
}
