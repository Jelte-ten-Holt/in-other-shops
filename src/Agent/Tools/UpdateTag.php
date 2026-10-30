<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use Illuminate\Support\Facades\DB;
use InOtherShops\Agent\AgentTool;
use InOtherShops\Agent\Support\TaxonomyRecordShape;
use InOtherShops\Agent\Support\TaxonomyWriteInput;
use InOtherShops\Taxonomy\Taxonomy;
use InvalidArgumentException;

/**
 * Patches a tag: only the keys passed change. The slug is not editable here —
 * it is part of public filter URLs, and nothing redirects an old one.
 */
final class UpdateTag extends AgentTool
{
    public static function identifier(): string
    {
        return 'update_tag';
    }

    public static function displayName(): string
    {
        return 'Update tag';
    }

    public function description(): string
    {
        return 'Update a tag identified by slug. Only the keys you pass change. `name` maps locale → name and updates just the locales given. The slug cannot be changed. '.TaxonomyWriteInput::tagTypeHelp().' Setting `is_active` to false hides the tag from listings. Returns the stored tag.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug of the tag to update.'],
                'name' => TaxonomyWriteInput::localeMapSchema('Horror'),
                'type' => TaxonomyWriteInput::tagTypeSchema(),
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
            return $this->failure('forbidden', 'update_tag requires the admin scope or the operator bearer token.');
        }

        $slug = $arguments['slug'] ?? null;

        if (! is_string($slug) || $slug === '') {
            throw new InvalidArgumentException("Field 'slug' is required and must be a non-empty string.");
        }

        $target = ['slug' => $slug];
        $tag = Taxonomy::tag()::query()->where('slug', $slug)->first();

        if ($tag === null) {
            return $this->failure('not_found', "No tag with slug '{$slug}'.", $target);
        }

        $names = array_key_exists('name', $arguments)
            ? TaxonomyWriteInput::localeMap($arguments['name'], 'name', requireDefault: false)
            : [];
        $attributes = $this->changedAttributes($arguments, $tag->type);

        DB::transaction(function () use ($tag, $attributes, $names): void {
            if ($attributes !== []) {
                $tag->fill($attributes)->save();
            }

            TaxonomyWriteInput::writeTranslations($tag, 'name', $names);
        });

        return [
            'ok' => true,
            'target' => $target,
            'data' => TaxonomyRecordShape::tag($tag->fresh()),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function changedAttributes(array $arguments, ?string $currentType): array
    {
        $attributes = [];

        if (array_key_exists('type', $arguments)) {
            $attributes['type'] = TaxonomyWriteInput::tagType($arguments['type'], $currentType);
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
