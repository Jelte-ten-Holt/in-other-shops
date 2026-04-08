<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Controllers;

use InOtherShops\Storefront\Actions\ListCategoryBrowsables;
use InOtherShops\Storefront\Resources\BrowsableResource;
use InOtherShops\Storefront\Resources\CategoryResource;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Taxonomy\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CategoryShowController
{
    public function __invoke(string $slug, Request $request, ListCategoryBrowsables $action): JsonResponse
    {
        $category = $this->findActiveCategory($slug);

        if ($category === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $items = $this->paginatedItems($action, $category, $request);

        return $this->buildResponse($category, $items, $request);
    }

    private function findActiveCategory(string $slug): ?Category
    {
        return Taxonomy::category()->query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with([
                'translations' => fn ($q) => $q->where('locale', app()->getLocale()),
                'children' => fn ($q) => $q->where('is_active', true)->orderBy('position')
                    ->with(['translations' => fn ($q2) => $q2->where('locale', app()->getLocale())]),
            ])
            ->first();
    }

    private function paginatedItems(ListCategoryBrowsables $action, Category $category, Request $request): mixed
    {
        $perPage = min((int) $request->input('per_page', config('storefront.defaults.per_page', 24)), 100);
        $page = max((int) $request->input('page', 1), 1);

        return $action($category, $perPage, $page);
    }

    private function buildResponse(Category $category, mixed $items, Request $request): JsonResponse
    {
        return response()->json([
            'data' => (new CategoryResource($category))->toArray($request),
            'items' => BrowsableResource::collection($items)->response()->getData(true),
        ]);
    }
}
