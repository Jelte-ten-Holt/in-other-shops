<?php

declare(strict_types=1);

namespace InOtherShops\Tax;

final class TaxConfig
{
    public static function homeJurisdiction(): ?string
    {
        $value = config('tax.home_jurisdiction');

        return $value === null ? null : (string) $value;
    }

    public static function jurisdictionForCountry(string $countryCode): ?string
    {
        $countryCode = strtoupper($countryCode);

        foreach ((array) config('tax.jurisdictions', []) as $identifier => $data) {
            $countries = array_map('strtoupper', (array) ($data['countries'] ?? []));

            if (in_array($countryCode, $countries, true)) {
                return (string) $identifier;
            }
        }

        return null;
    }

    public static function isInHomeJurisdiction(string $countryCode): bool
    {
        $home = self::homeJurisdiction();

        if ($home === null) {
            return false;
        }

        return self::jurisdictionForCountry($countryCode) === $home;
    }

    /**
     * @return array{rate_bps: int, name: string}
     */
    public static function exportRate(): array
    {
        $configured = (array) config('tax.export_rate', []);

        return [
            'rate_bps' => (int) ($configured['rate_bps'] ?? 0),
            'name' => (string) ($configured['name'] ?? 'Zero-rated export'),
        ];
    }
}
