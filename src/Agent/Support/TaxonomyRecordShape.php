<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The output of the tag and category write tools. Deliberately fuller than the
 * storefront resources the list tools return: those drop `is_active`,
 * `position` and the parent, and a caller that just changed one of them needs
 * to see it took.
 */
final class TaxonomyRecordShape
{
    /** @return array<string, mixed> */
    public static function tag(Model $tag): array
    {
        return [
            'slug' => $tag->slug,
            'names' => self::translations($tag, 'name'),
            'type' => $tag->type,
            'position' => (int) $tag->position,
            'is_active' => (bool) $tag->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function category(Model $category): array
    {
        return [
            'slug' => $category->slug,
            'names' => self::translations($category, 'name'),
            'descriptions' => self::translations($category, 'description'),
            'parent' => $category->parent?->slug,
            'position' => (int) $category->position,
            'is_active' => (bool) $category->is_active,
        ];
    }

    /** @return array<string, string> */
    private static function translations(Model $model, string $field): array
    {
        return $model->translations
            ->where('field', $field)
            ->pluck('value', 'locale')
            ->sortKeys()
            ->all();
    }
}
