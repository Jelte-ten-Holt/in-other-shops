<?php

declare(strict_types=1);

namespace InOtherShops\Support;

/**
 * Default `label()` for a string-backed enum: the backing value with
 * underscores turned to spaces and the first letter upper-cased — sentence
 * case (`partially_received` → "Partially received"), the dominant convention
 * across the package's admin labels.
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
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
