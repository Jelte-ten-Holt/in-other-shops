<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InOtherShops\Agent\AgentTool;
use InOtherShops\Agent\Support\TaxonomyRecordShape;
use InOtherShops\Agent\Support\TaxonomyWriteInput;
use InOtherShops\Taxonomy\Taxonomy;

/**
 * Creates a category, at the root or under a parent, the way the admin's
 * category form would.
 */
final class CreateCategory extends AgentTool
{
    public static function identifier(): string
    {
        return 'create_category';
    }

    public static function displayName(): string
    {
        return 'Create category';
    }

    public function description(): string
    {
        return 'Create a category. `name` maps locale → name and must include the default locale ('.TaxonomyWriteInput::defaultLocale().'); `description` takes the same shape and is optional. `parent` is the slug of an existing category (use list_categories), or omit it for a top-level category. `slug` defaults to the slugified default-locale name; it is the category page URL and cannot be changed through the agent afterwards. Returns the stored category.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => TaxonomyWriteInput::localeMapSchema('Games'),
                'description' => TaxonomyWriteInput::localeMapSchema('Games we have released.'),
                'slug' => ['type' => 'string', 'description' => 'Optional. Lowercase and hyphenated; derived from the default-locale name when omitted.'],
                'parent' => ['type' => 'string', 'description' => 'Slug of the parent category. Omit for a top-level category.'],
                'position' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Sort position among its siblings. Defaults to 0.'],
                'is_active' => ['type' => 'boolean', 'description' => 'Defaults to true. An inactive category is hidden from listings.'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function __invoke(array $arguments): array
    {
        if (! $this->isAdmin()) {
            return $this->failure('forbidden', 'create_category requires the admin scope or the operator bearer token.');
        }

        $categoryClass = Taxonomy::category();
        $names = TaxonomyWriteInput::localeMap($arguments['name'] ?? null, 'name', requireDefault: true);
        $descriptions = TaxonomyWriteInput::localeMap($arguments['description'] ?? [], 'description', requireDefault: false);
        $slug = TaxonomyWriteInput::slug($arguments['slug'] ?? null, $names);
        $target = ['slug' => $slug];
        $attributes = [
            'slug' => $slug,
            'parent_id' => isset($arguments['parent'])
                ? TaxonomyWriteInput::categoryBySlug($categoryClass, $arguments['parent'], 'parent')->getKey()
                : null,
            'position' => TaxonomyWriteInput::position($arguments['position'] ?? 0),
            'is_active' => TaxonomyWriteInput::isActive($arguments['is_active'] ?? true),
        ];

        if ($categoryClass::query()->where('slug', $slug)->exists()) {
            return $this->failure('slug_taken', "A category with slug '{$slug}' already exists.", $target);
        }

        try {
            $category = DB::transaction(function () use ($categoryClass, $attributes, $names, $descriptions) {
                $category = $categoryClass::query()->create($attributes);
                TaxonomyWriteInput::writeTranslations($category, 'name', $names);
                TaxonomyWriteInput::writeTranslations($category, 'description', $descriptions);

                return $category;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->failure('slug_taken', "A category with slug '{$slug}' already exists.", $target);
        }

        return [
            'ok' => true,
            'target' => $target,
            'data' => TaxonomyRecordShape::category($category->fresh()),
        ];
    }
}
