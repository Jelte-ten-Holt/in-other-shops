<?php

declare(strict_types=1);

namespace InOtherShops\Currency\Support;

use NumberFormatter;
use RuntimeException;

/**
 * Memoized intl NumberFormatter instances per display locale. Formatter
 * construction is not free and Currency::format() runs in per-line loops
 * (cart items, order tables), so instances are cached for the process
 * lifetime. Formatters are stateless after construction — sharing them is
 * safe.
 */
final class MoneyFormatter
{
    /** @var array<string, NumberFormatter> */
    private static array $currency = [];

    /** @var array<string, NumberFormatter> */
    private static array $percent = [];

    public static function formatCurrency(string $locale, float $value, string $currencyCode): string
    {
        self::$currency[$locale] ??= new NumberFormatter($locale, NumberFormatter::CURRENCY);

        $formatted = self::$currency[$locale]->formatCurrency($value, $currencyCode);

        if ($formatted === false) {
            throw new RuntimeException("Failed to format {$currencyCode} amount for locale [{$locale}].");
        }

        return $formatted;
    }

    public static function formatPercent(string $locale, float $ratio): string
    {
        if (! isset(self::$percent[$locale])) {
            $formatter = new NumberFormatter($locale, NumberFormatter::PERCENT);
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

            self::$percent[$locale] = $formatter;
        }

        $formatted = self::$percent[$locale]->format($ratio);

        if ($formatted === false) {
            throw new RuntimeException("Failed to format percentage for locale [{$locale}].");
        }

        return $formatted;
    }
}
