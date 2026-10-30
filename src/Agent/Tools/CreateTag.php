<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InOtherShops\Agent\AgentTool;
use InOtherShops\Agent\Support\TaxonomyRecordShape;
use InOtherShops\Agent\Support\TaxonomyWriteInput;
use InOtherShops\Taxonomy\Support\TagTypes;
use InOtherShops\Taxonomy\Taxonomy;
use InvalidArgumentException;

/**
 * Creates a tag, the way the admin's tag form would. `type` must be stated
 * when the project declares a tag-type vocabulary, because what an untyped tag
 * means is the project's (in-other-worlds treats it as a public, followable
 * topic) and the caller should choose that rather than fall into it.
 */
final class CreateTag extends AgentTool
{
    public static function identifier(): string
    {
        return 'create_tag';
    }

    public static function displayName(): string
    {
        return 'Create tag';
    }

    public function description(): string
    {
        return 'Create a tag. `name` maps locale → name and must include the default locale ('.TaxonomyWriteInput::defaultLocale().'). `slug` defaults to the slugified default-locale name; it appears in public filter URLs and cannot be changed through the agent afterwards. '.TaxonomyWriteInput::tagTypeHelp().' Returns the stored tag. Attach it to content with the content tools.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => TaxonomyWriteInput::localeMapSchema('Horror'),
                'slug' => ['type' => 'string', 'description' => 'Optional. Lowercase and hyphenated; derived from the default-locale name when omitted.'],
                'type' => TaxonomyWriteInput::tagTypeSchema(),
                'position' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Sort position. Defaults to 0.'],
                'is_active' => ['type' => 'boolean', 'description' => 'Defaults to true. An inactive tag is hidden from listings.'],
            ],
            'required' => TagTypes::isConfigured() ? ['name', 'type'] : ['name'],
            'additionalProperties' => false,
        ];
    }

    public function __invoke(array $arguments): array
    {
        if (! $this->isAdmin()) {
            return $this->failure('forbidden', 'create_tag requires the admin scope or the operator bearer token.');
        }

        $names = TaxonomyWriteInput::localeMap($arguments['name'] ?? null, 'name', requireDefault: true);
        $slug = TaxonomyWriteInput::slug($arguments['slug'] ?? null, $names);
        $target = ['slug' => $slug];
        $attributes = [
            'slug' => $slug,
            'type' => $this->resolveType($arguments),
            'position' => TaxonomyWriteInput::position($arguments['position'] ?? 0),
            'is_active' => TaxonomyWriteInput::isActive($arguments['is_active'] ?? true),
        ];

        $tagClass = Taxonomy::tag();

        if ($tagClass::query()->where('slug', $slug)->exists()) {
            return $this->failure('slug_taken', "A tag with slug '{$slug}' already exists.", $target);
        }

        try {
            $tag = DB::transaction(function () use ($tagClass, $attributes, $names) {
                $tag = $tagClass::query()->create($attributes);
                TaxonomyWriteInput::writeTranslations($tag, 'name', $names);

                return $tag;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->failure('slug_taken', "A tag with slug '{$slug}' already exists.", $target);
        }

        return [
            'ok' => true,
            'target' => $target,
            'data' => TaxonomyRecordShape::tag($tag->fresh()),
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function resolveType(array $arguments): ?string
    {
        if (TagTypes::isConfigured() && ! array_key_exists('type', $arguments)) {
            throw new InvalidArgumentException("Field 'type' is required here: ".TaxonomyWriteInput::tagTypeHelp());
        }

        return TaxonomyWriteInput::tagType($arguments['type'] ?? null);
    }
}
