<?php

declare(strict_types=1);

namespace InOtherShops\Shipping;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Shipping\DTOs\ShippingMethod;
use InOtherShops\Shipping\DTOs\ShippingZone;

final class ShippingConfig
{
    /**
     * @return array<string, ShippingZone> indexed by identifier
     */
    public static function zones(): array
    {
        $zones = [];

        foreach ((array) config('shipping.zones', []) as $identifier => $data) {
            $zones[(string) $identifier] = self::buildZone((string) $identifier, (array) $data);
        }

        return $zones;
    }

    public static function zone(string $identifier): ?ShippingZone
    {
        return self::zones()[$identifier] ?? null;
    }

    public static function zoneByCountry(string $countryCode): ?ShippingZone
    {
        $countryCode = strtoupper($countryCode);

        foreach (self::zones() as $zone) {
            if ($zone->includes($countryCode)) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * @return array<string, ShippingMethod> indexed by identifier
     */
    public static function methods(): array
    {
        $methods = [];

        foreach ((array) config('shipping.methods', []) as $identifier => $data) {
            $methods[(string) $identifier] = self::buildMethod((string) $identifier, (array) $data);
        }

        return $methods;
    }

    public static function method(string $identifier): ?ShippingMethod
    {
        return self::methods()[$identifier] ?? null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function buildZone(string $identifier, array $data): ShippingZone
    {
        return new ShippingZone(
            identifier: $identifier,
            name: (string) ($data['name'] ?? $identifier),
            currency: Currency::from((string) ($data['currency'] ?? Currency::EUR->value)),
            countries: array_values(array_map(
                fn ($cc): string => strtoupper((string) $cc),
                (array) ($data['countries'] ?? []),
            )),
            freeShippingThreshold: isset($data['free_shipping_threshold'])
                ? (int) $data['free_shipping_threshold']
                : null,
            sortOrder: (int) ($data['sort_order'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function buildMethod(string $identifier, array $data): ShippingMethod
    {
        return new ShippingMethod(
            identifier: $identifier,
            name: (string) ($data['name'] ?? $identifier),
            sortOrder: (int) ($data['sort_order'] ?? 0),
            isActive: (bool) ($data['is_active'] ?? true),
            rates: array_map(fn ($v): int => (int) $v, (array) ($data['rates'] ?? [])),
        );
    }
}
