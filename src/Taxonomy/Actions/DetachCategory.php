<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Actions;

use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Events\CategoryDetached;
use InOtherShops\Taxonomy\Models\Category;
use Illuminate\Database\Eloquent\Model;

final class DetachCategory
{
    public function __invoke(Model&HasCategories $model, Category $category): void
    {
        $removed = $model->categories()->detach($category);

        if ($removed === 0) {
            return;
        }

        CategoryDetached::dispatch($model, $category);
    }
}
