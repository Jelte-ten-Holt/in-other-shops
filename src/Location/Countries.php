<?php

declare(strict_types=1);

namespace InOtherShops\Location;

use Collator;
use Locale;

/**
 * Localized country names for ISO 3166-1 alpha-2 codes.
 *
 * **This class ships no country data, in any language, deliberately.** Names
 * come from ICU/CLDR via ext-intl, which the package already requires. That
 * matters more than it looks: a shipped name table would mean every consumer
 * that adds a language forces a package release carrying ~250 translations for
 * it — used or not, maintained by whoever happens to be here. Going through ICU
 * makes a new consumer locale cost nothing at all.
 *
 * WHICH countries a shop offers is not this class's business — that is the
 * consumer's shipping zones (or whatever else it uses). This only answers
 * "what is this code called, in this language".
 *
 * Resolution order per code: consumer override → ICU → the code itself.
 * `location.country_names` is empty by default and exists for the handful of
 * cases where ICU's wording is not the shop's (ICU says "Chequia"; a shop may
 * prefer "República Checa"). Note that ICU also aliases deprecated codes — `AN`
 * resolves to "Curazao" — so a stale code in a consumer's zone list resolves to
 * a real name rather than failing. That is visible the moment anyone reads the
 * picker, and is not worth an ISO code table to defend against.
 */
final class Countries
{
    /**
     * The display name for one code, or the code itself when nothing resolves.
     *
     * Falling back to the code keeps a genuinely unassigned entry (`XX`)
     * legible instead of rendering a blank option that cannot be reasoned about.
     */
    public static function name(string $code, ?string $locale = null): string
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return '';
        }

        $locale ??= app()->getLocale();

        $override = config("location.country_names.{$code}.{$locale}");

        if (is_string($override) && $override !== '') {
            return $override;
        }

        // The leading '-' makes this a region subtag rather than a language:
        // getDisplayRegion('DE') would read "DE" as a language code.
        $display = Locale::getDisplayRegion('-'.$code, $locale);

        return $display !== '' ? $display : $code;
    }

    /**
     * The given codes as `{code, name}` rows, sorted by the LOCALIZED name.
     *
     * Sorting on the name rather than the code is the whole point of having
     * names: a list ordered "AT, AU, BE, BG" reads as noise to a shopper. The
     * sort is accent-aware via Collator, without which Spanish misplaces every
     * accented name ("Éire", "Bélgica") relative to its unaccented neighbours.
     *
     * Codes are upper-cased and de-duplicated; blanks are dropped.
     *
     * @param  iterable<string>  $codes
     * @return list<array{code: string, name: string}>
     */
    public static function options(iterable $codes, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        $named = [];

        foreach ($codes as $code) {
            $code = strtoupper(trim((string) $code));

            if ($code === '' || isset($named[$code])) {
                continue;
            }

            $named[$code] = self::name($code, $locale);
        }

        $collator = self::collator($locale);

        uasort($named, $collator !== null
            ? static fn (string $a, string $b): int => $collator->compare($a, $b) ?: 0
            : static fn (string $a, string $b): int => strcasecmp($a, $b));

        $options = [];

        foreach ($named as $code => $name) {
            $options[] = ['code' => (string) $code, 'name' => $name];
        }

        return $options;
    }

    /**
     * A collator for the locale, falling back to the root locale and then to
     * null (the caller degrades to strcasecmp). ext-intl is a hard requirement,
     * so this is about an unusable locale string, not a missing extension.
     */
    private static function collator(string $locale): ?Collator
    {
        return Collator::create($locale) ?? Collator::create('root');
    }
}
