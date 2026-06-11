<?php

declare(strict_types=1);

namespace InOtherShops\Currency\Support;

/**
 * Resolves which locale's conventions money display strings use.
 *
 * Resolution order: per-request override (set by SetMoneyDisplayLocale on
 * admin panel requests) > `currency.display_locale` config (project-level
 * escape hatch, ships null) > the application locale. Public surfaces
 * therefore follow the app locale by default — "the language in which they
 * bought" — while the admin follows the operator's own browser settings.
 *
 * The override is process state: anything that sets it MUST clear it in the
 * same request (see the middleware's try/finally). Queued jobs never see an
 * override; they must pass an explicit locale (e.g. the order's stored
 * locale) to Currency::format() instead of trusting ambient state.
 */
final class DisplayLocale
{
    private static ?string $override = null;

    public static function set(?string $locale): void
    {
        self::$override = $locale;
    }

    public static function clear(): void
    {
        self::$override = null;
    }

    public static function resolve(): string
    {
        return self::$override
            ?? config('currency.display_locale')
            ?? app()->getLocale();
    }
}
