<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use InOtherShops\Agent\AgentTool;
use InOtherShops\Storefront\Http\Resources\TagResource;
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
        return 'List tags with an optional type filter. Returns active tags only unless include_inactive is true.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tag_type' => [
                    'type' => 'string',
                    'description' => 'Filter by tag type (e.g. "featured", "hidden_on_front"). Omit to return all types.',
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
