<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use InOtherShops\Agent\AgentTool;
use InOtherShops\Storefront\Resources\CategoryResource;
use InOtherShops\Taxonomy\Taxonomy;
use Illuminate\Http\Request;

final class ListCategories extends AgentTool
{
    public static function identifier(): string
    {
        return 'list_categories';
    }

    public static function displayName(): string
    {
        return 'List categories';
    }

    public function description(): string
    {
        return 'List root categories with their immediate children.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_inactive' => [
                    'type' => 'boolean',
                    'description' => 'Include categories with is_active=false. Default false.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function __invoke(array $arguments): array
    {
        $includeInactive = (bool) ($arguments['include_inactive'] ?? false);
        $locale = app()->getLocale();

        $categories = Taxonomy::category()::query()
            ->whereNull('parent_id')
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->with([
                'translations' => fn ($q) => $q->where('locale', $locale),
                'children' => fn ($q) => $q
                    ->when(! $includeInactive, fn ($inner) => $inner->where('is_active', true))
                    ->orderBy('position')
                    ->with(['translations' => fn ($q2) => $q2->where('locale', $locale)]),
            ])
            ->orderBy('position')
            ->get();

        $request = Request::create('/', 'GET');

        return [
            'data' => $categories
                ->map(fn ($category) => (new CategoryResource($category))->toArray($request))
                ->all(),
        ];
    }
}
