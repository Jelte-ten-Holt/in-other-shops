<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use Illuminate\Support\Facades\DB;
use InOtherShops\Agent\AgentTool;
use InOtherShops\Agent\Support\TaxonomyRecordShape;
use InOtherShops\Agent\Support\TaxonomyWriteInput;
use InOtherShops\Taxonomy\Exceptions\CategoryCycleException;
use InOtherShops\Taxonomy\Taxonomy;
use InvalidArgumentException;

/**
 * Patches a category: only the keys passed change. Moving it (`parent`) keeps
 * the category counts right through the same observer the admin's save goes
 * through, and a move that would make the tree loop is refused there. The slug
 * is not editable here — it is the category page URL, and nothing redirects an
 * old one.
 */
final class UpdateCategory extends AgentTool
{
    public static function identifier(): string
    {
        return 'update_category';
    }

    public static function displayName(): string
    {
        return 'Update category';
    }

    public function description(): string
    {
        return 'Update a category identified by slug. Only the keys you pass change. `name` and `description` map locale → text and update just the locales given. `parent` moves it: a category slug, or null for top level; a category cannot move under itself or one of its own subcategories. The slug cannot be changed. Setting `is_active` to false hides the category and its page. Returns the stored category.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug of the category to update.'],
                'name' => TaxonomyWriteInput::localeMapSchema('Games'),
                'description' => TaxonomyWriteInput::localeMapSchema('Games we have released.'),
                'parent' => ['type' => ['string', 'null'], 'description' => 'Slug of the new parent category, or null to make it top level.'],
                'position' => ['type' => 'integer', 'minimum' => 0],
                'is_active' => ['type' => 'boolean'],
            ],
            'required' => ['slug'],
            'additionalProperties' => false,
        ];
    }

    public function __invoke(array $arguments): array
    {
        if (! $this->isAdmin()) {
            return $this->failure('forbidden', 'update_category requires the admin scope or the operator bearer token.');
        }

        $slug = $arguments['slug'] ?? null;

        if (! is_string($slug) || $slug === '') {
            throw new InvalidArgumentException("Field 'slug' is required and must be a non-empty string.");
        }

        $target = ['slug' => $slug];
        $categoryClass = Taxonomy::category();
        $category = $categoryClass::query()->where('slug', $slug)->first();

        if ($category === null) {
            return $this->failure('not_found', "No category with slug '{$slug}'.", $target);
        }

        $names = array_key_exists('name', $arguments)
            ? TaxonomyWriteInput::localeMap($arguments['name'], 'name', requireDefault: false)
            : [];
        $descriptions = array_key_exists('description', $arguments)
            ? TaxonomyWriteInput::localeMap($arguments['description'], 'description', requireDefault: false)
            : [];
        $attributes = $this->changedAttributes($arguments, $categoryClass);

        try {
            DB::transaction(function () use ($category, $attributes, $names, $descriptions): void {
                if ($attributes !== []) {
                    $category->fill($attributes)->save();
                }

                TaxonomyWriteInput::writeTranslations($category, 'name', $names);
                TaxonomyWriteInput::writeTranslations($category, 'description', $descriptions);
            });
        } catch (CategoryCycleException $e) {
            return $this->failure('category_cycle', $e->getMessage(), $target);
        }

        return [
            'ok' => true,
            'target' => $target,
            'data' => TaxonomyRecordShape::category($category->fresh()),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function changedAttributes(array $arguments, string $categoryClass): array
    {
        $attributes = [];

        if (array_key_exists('parent', $arguments)) {
            $attributes['parent_id'] = $arguments['parent'] === null
                ? null
                : TaxonomyWriteInput::categoryBySlug($categoryClass, $arguments['parent'], 'parent')->getKey();
        }

        if (array_key_exists('position', $arguments)) {
            $attributes['position'] = TaxonomyWriteInput::position($arguments['position']);
        }

        if (array_key_exists('is_active', $arguments)) {
            $attributes['is_active'] = TaxonomyWriteInput::isActive($arguments['is_active']);
        }

        return $attributes;
    }
}
