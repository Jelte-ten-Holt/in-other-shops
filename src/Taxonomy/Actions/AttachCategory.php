<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Actions;

use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Events\CategoryAttached;
use InOtherShops\Taxonomy\Models\Category;
use Illuminate\Database\Eloquent\Model;

final class AttachCategory
{
    public function __invoke(Model&HasCategories $model, Category $category): void
    {
        $model->categories()->attach($category);

        CategoryAttached::dispatch($model, $category);
    }
}
