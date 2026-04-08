<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Controllers;

use InOtherShops\Storefront\Resources\CategoryResource;
use InOtherShops\Taxonomy\Taxonomy;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CategoryListController
{
    public function __invoke(): AnonymousResourceCollection
    {
        $categories = Taxonomy::category()->query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with([
                'translations' => fn ($q) => $q->where('locale', app()->getLocale()),
                'children' => fn ($q) => $q->where('is_active', true)->orderBy('position')
                    ->with(['translations' => fn ($q2) => $q2->where('locale', app()->getLocale())]),
            ])
            ->orderBy('position')
            ->get();

        return CategoryResource::collection($categories);
    }
}
