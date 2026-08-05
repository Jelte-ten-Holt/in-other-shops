<?php

declare(strict_types=1);

namespace InOtherShops\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A display label for an arbitrary consumer model.
 *
 * Consumer catalogs do not agree on where their display text lives. One keeps
 * `name` as a real column; another keeps it in the polymorphic `translations`
 * table and surfaces it through an overridden `getAttribute()`. Both satisfy
 * every contract this package defines, because no contract ever promised a
 * column.
 *
 * The rule that follows: package code needing a label must go through the
 * MODEL, never the query builder. `pluck('name', 'id')` reads the database
 * directly, bypasses the accessor, and dies with "Unknown column 'name'"
 * against a translation-backed catalog — as the order admin did until this
 * class existed. `getAttribute()` works for both shapes.
 *
 * Callers that load many records should eager-load `translations` first, or
 * this resolves one query per record.
 */
final class ModelLabel
{
    /**
     * Tried in order. `slug` is last because it is a URL token rather than
     * prose — a correct label only when nothing nameable exists.
     */
    private const array ATTRIBUTES = ['name', 'title', 'label', 'slug'];

    public static function for(Model $model): string
    {
        foreach (self::ATTRIBUTES as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        // Never null and never empty: callers put this straight into a Filament
        // option list, where a blank label renders an unpickable row.
        return $model->getMorphClass().' #'.$model->getKey();
    }
}
