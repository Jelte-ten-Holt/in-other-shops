<?php

declare(strict_types=1);

namespace InOtherShops\Currency\Enums;

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

    public function format(int $amount): string
    {
        $value = number_format(
            num: $amount / (10 ** $this->decimals()),
            decimals: $this->decimals(),
            decimal_separator: '.',
            thousands_separator: ',',
        );

        return $this->symbol().$value;
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
