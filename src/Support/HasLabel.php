<?php

declare(strict_types=1);

namespace InOtherShops\Support;

use Illuminate\Container\Container;

/**
 * Default `label()` for a string-backed enum: a localized admin label, falling
 * back to the backing value with underscores turned to spaces and the first
 * letter upper-cased — sentence case (`partially_received` → "Partially
 * received"), the dominant convention across the package's admin labels.
 *
 * Localization: `defaultLabel()` looks up `shops-{domain}::enums.{Enum}.{value}`
 * (e.g. `shops-commerce::enums.OrderStatus.partially_received`) and returns the
 * translation when present, otherwise the sentence-case transform. The domain
 * is derived from the enum's namespace (`InOtherShops\{Domain}\...`), matching
 * the per-domain translation namespace {@see DomainServiceProvider}. An enum
 * with no lang entry — including consumer-app enums outside `InOtherShops\` —
 * renders exactly as before, so this is fully backward compatible.
 *
 * Enums whose label can't be derived from the value (an ampersand, an
 * abbreviation) `use HasLabel` and override `label()`, delegating the ordinary
 * cases to {@see self::defaultLabel()} rather than re-deriving the formula.
 *
 * Satisfies {@see Transitionable::label()} for status enums that use it.
 */
trait HasLabel
{
    public function label(): string
    {
        return $this->defaultLabel();
    }

    protected function defaultLabel(): string
    {
        $fallback = ucfirst(str_replace('_', ' ', $this->value));

        $key = $this->labelTranslationKey();

        if ($key === null) {
            return $fallback;
        }

        // Resolve via the container, not the Lang facade: the trait must be
        // safe to call outside a booted app (pure-unit tests, early boot),
        // where the facade root is unset. No translator bound, or no entry for
        // the key → sentence-case fallback.
        $container = Container::getInstance();

        if (! $container->bound('translator')) {
            return $fallback;
        }

        $translator = $container->make('translator');

        return $translator->has($key) ? $translator->get($key) : $fallback;
    }

    /**
     * `shops-{domain}::enums.{Enum}.{value}` for a package enum, or null for an
     * enum outside `InOtherShops\` (a consumer enum localizes on its own terms).
     */
    private function labelTranslationKey(): ?string
    {
        $parts = explode('\\', static::class);

        if (($parts[0] ?? null) !== 'InOtherShops' || ! isset($parts[1])) {
            return null;
        }

        $namespace = 'shops-'.strtolower($parts[1]);
        $enum = $parts[count($parts) - 1];

        return $namespace.'::enums.'.$enum.'.'.$this->value;
    }
}
