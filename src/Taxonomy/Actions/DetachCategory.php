<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Actions;

use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Events\CategoryDetached;
use InOtherShops\Taxonomy\Models\Category;
use Illuminate\Database\Eloquent\Model;

final class DetachCategory
{
    /**
     * @param  Model&HasCategories  $model
     */
    public function __invoke(Model $model, Category $category): void
    {
        $model->categories()->detach($category);

        CategoryDetached::dispatch($model, $category);
    }
}
