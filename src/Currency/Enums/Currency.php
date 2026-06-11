<?php

declare(strict_types=1);

namespace InOtherShops\Currency\Enums;

use InOtherShops\Currency\Support\DisplayLocale;
use InOtherShops\Currency\Support\MoneyFormatter;

enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';

    public function symbol(): string
    {
        return match ($this) {
            self::EUR => '€',
            self::USD => '$',
            self::GBP => '£',
        };
    }

    public function decimals(): int
    {
        return match ($this) {
            self::EUR, self::USD, self::GBP => 2,
        };
    }

    /**
     * Format an integer minor-unit amount for human display, using the
     * display locale's conventions (separators AND symbol placement, from
     * CLDR): 'en' -> "€12.50", 'de'/'es' -> "12,50 €".
     *
     * Pass an explicit $locale in any non-request context (queued mail
     * formats with the order's stored locale — ambient app locale in a
     * worker is just the app default). In request context the ambient
     * resolution (DisplayLocale) is correct: app locale on public surfaces,
     * the admin's Accept-Language inside Filament panels.
     *
     * Never use this output as a machine format (APIs consumed by code,
     * structured data, gateway payloads) — those stay on raw integer cents
     * or hardcoded period formats.
     */
    public function format(int $amount, ?string $locale = null): string
    {
        return MoneyFormatter::formatCurrency(
            $locale ?? DisplayLocale::resolve(),
            $amount / (10 ** $this->decimals()),
            $this->value,
        );
    }

    /**
     * @return array<self>
     */
    public static function enabled(): array
    {
        /** @var array<string>|null $configured */
        $configured = config('currency.enabled');

        if ($configured === null || $configured === []) {
            return self::cases();
        }

        return array_values(array_filter(
            self::cases(),
            fn (self $case): bool => in_array($case->value, $configured, true),
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function enabledOptions(): array
    {
        $options = [];

        foreach (self::enabled() as $case) {
            $options[$case->value] = $case->value;
        }

        return $options;
    }
}
