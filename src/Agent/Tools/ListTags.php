<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use InOtherShops\Agent\AgentTool;
use InOtherShops\Storefront\Resources\TagResource;
use InOtherShops\Taxonomy\Taxonomy;
use Illuminate\Http\Request;

final class ListTags extends AgentTool
{
    public static function identifier(): string
    {
        return 'list_tags';
    }

    public static function displayName(): string
    {
        return 'List tags';
    }

    public function description(): string
    {
        return 'List tags with an optional type filter. Returns active tags only unless include_inactive is true. Tag types are project-defined free-form strings — typical conventions: a type per entity class the tag may be attached to ("content", "product"), or a display-behavior marker ("hidden_on_front", "featured"). To discover what types exist on this site, call without a filter and read the `type` field on each row.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tag_type' => [
                    'type' => 'string',
                    'description' => 'Filter by tag type. Project-defined free-form string (no fixed enum). Common conventions: "content"/"product"/"bundle" (scopes the tag to one entity class) or "hidden_on_front"/"featured" (display-behavior markers). Omit to return all types.',
                ],
                'include_inactive' => [
                    'type' => 'boolean',
                    'description' => 'Include tags with is_active=false. Default false.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function __invoke(array $arguments): array
    {
        $tagType = isset($arguments['tag_type']) && is_string($arguments['tag_type']) && $arguments['tag_type'] !== ''
            ? $arguments['tag_type']
            : null;
        $includeInactive = (bool) ($arguments['include_inactive'] ?? false);
        $locale = app()->getLocale();

        $tags = Taxonomy::tag()::query()
            ->when($tagType !== null, fn ($q) => $q->where('type', $tagType))
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->with(['translations' => fn ($q) => $q->where('locale', $locale)])
            ->orderBy('position')
            ->get();

        $request = Request::create('/', 'GET');

        return [
            'ok' => true,
            'data' => $tags
                ->map(fn ($tag) => (new TagResource($tag))->toArray($request))
                ->all(),
        ];
    }
}
