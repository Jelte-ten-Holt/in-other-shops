<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InOtherShops\Taxonomy\Support\TagTypes;
use InvalidArgumentException;

/**
 * Input rules shared by the tag and category write tools (create_tag,
 * update_tag, create_category, update_category). Malformed input throws —
 * a caller bug, per the agent tool conventions — and every rule mirrors what
 * the admin form for the same record would accept.
 */
final class TaxonomyWriteInput
{
    /**
     * The locale a name is required in. Read from the Translation config, not
     * `app()->getLocale()`: the MCP endpoint runs outside any locale-setting
     * middleware, and a Spanish-first shop sets this to `es`.
     */
    public static function defaultLocale(): string
    {
        return (string) config('translation.default', 'en');
    }

    /** @return list<string> */
    public static function locales(): array
    {
        $locales = config('translation.locales', [self::defaultLocale()]);

        return array_values(array_map('strval', is_array($locales) ? $locales : [self::defaultLocale()]));
    }

    /**
     * A `{locale: text}` map. Keys must be configured locales, values non-empty
     * strings. `$requireDefault` is the create rule: a record without a name in
     * the default locale renders nameless wherever no other locale applies.
     *
     * @return array<string, string>
     */
    public static function localeMap(mixed $value, string $field, bool $requireDefault): array
    {
        $default = self::defaultLocale();

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("Field '{$field}' must be an object of locale → text, e.g. {\"{$default}\": \"…\"}.");
        }

        $map = [];

        foreach ($value as $locale => $text) {
            $locale = (string) $locale;

            if (! in_array($locale, self::locales(), true)) {
                throw new InvalidArgumentException("Unknown locale '{$locale}' in '{$field}'. Configured locales: ".implode(', ', self::locales()).'.');
            }

            if (! is_string($text) || trim($text) === '') {
                throw new InvalidArgumentException("Field '{$field}.{$locale}' must be a non-empty string.");
            }

            $map[$locale] = trim($text);
        }

        if ($requireDefault && ! array_key_exists($default, $map)) {
            throw new InvalidArgumentException("Field '{$field}' must include the default locale '{$default}'.");
        }

        return $map;
    }

    /**
     * An explicit slug must already be in slug form; otherwise it is derived
     * from the default-locale name, as the admin form derives it.
     *
     * @param  array<string, string>  $names
     */
    public static function slug(mixed $explicit, array $names): string
    {
        if ($explicit !== null) {
            if (! is_string($explicit) || $explicit === '' || Str::slug($explicit) !== $explicit) {
                throw new InvalidArgumentException("Field 'slug' must be a lowercase, hyphenated slug, e.g. 'one-pager'.");
            }

            return $explicit;
        }

        $derived = Str::slug($names[self::defaultLocale()] ?? '');

        if ($derived === '') {
            throw new InvalidArgumentException("No slug can be derived from the name; pass 'slug' explicitly.");
        }

        return $derived;
    }

    /**
     * Null leaves the tag untyped. When the project declares a vocabulary, the
     * value must be one of it — or the tag's current type, so an update that
     * echoes a type from before the vocabulary existed is not refused (the same
     * allowance the admin select makes, see TagTypes::options()).
     */
    public static function tagType(mixed $value, ?string $current = null): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Field 'type' must be a non-empty string or null.");
        }

        if (TagTypes::isConfigured() && ! array_key_exists($value, TagTypes::options($current))) {
            throw new InvalidArgumentException("Unknown tag type '{$value}'. Allowed: ".implode(', ', array_keys(TagTypes::options($current))).', or null.');
        }

        return $value;
    }

    /**
     * The JSON schema for `type`: an enum when the project declares a
     * vocabulary, free text otherwise.
     *
     * @return array<string, mixed>
     */
    public static function tagTypeSchema(): array
    {
        if (! TagTypes::isConfigured()) {
            return ['type' => ['string', 'null'], 'description' => 'Free-text tag type, or null for none.'];
        }

        return [
            'type' => ['string', 'null'],
            'enum' => [...array_keys(TagTypes::options()), null],
            'description' => self::tagTypeHelp(),
        ];
    }

    /** What each declared type means, so the caller sees it before choosing. */
    public static function tagTypeHelp(): string
    {
        if (! TagTypes::isConfigured()) {
            return '`type` is an optional free-text label.';
        }

        $explained = [];

        foreach (TagTypes::options() as $value => $label) {
            $description = TagTypes::descriptions()[$value] ?? null;
            $explained[] = $description === null ? "{$value} ({$label})" : "{$value} — {$description}";
        }

        return '`type` is null (untyped) or one of: '.implode('; ', $explained).'.';
    }

    public static function position(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Field 'position' must be an integer of 0 or more.");
        }

        return $value;
    }

    public static function isActive(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException("Field 'is_active' must be a boolean.");
        }

        return $value;
    }

    /**
     * Resolve a category by slug, for a `parent` reference. An unknown slug is
     * a caller bug (throw), as an unknown taxonomy slug is in the content tools.
     */
    public static function categoryBySlug(string $categoryClass, mixed $slug, string $field): Model
    {
        if (! is_string($slug) || $slug === '') {
            throw new InvalidArgumentException("Field '{$field}' must be a category slug.");
        }

        $category = $categoryClass::query()->where('slug', $slug)->first();

        if ($category === null) {
            throw new InvalidArgumentException("Unknown category slug '{$slug}' in '{$field}'. Use list_categories.");
        }

        return $category;
    }

    /** @param array<string, string> $map */
    public static function writeTranslations(Model $model, string $field, array $map): void
    {
        foreach ($map as $locale => $text) {
            $model->setTranslation($field, $locale, $text);
        }
    }

    /** @return array<string, mixed> */
    public static function localeMapSchema(string $example): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => ['type' => 'string'],
            'description' => 'Locale → text, e.g. {"'.self::defaultLocale().'": "'.$example.'"}. Configured locales: '.implode(', ', self::locales()).'.',
        ];
    }
}
