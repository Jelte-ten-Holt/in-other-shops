<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Actions;

use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Events\CategoryDetached;
use InOtherShops\Taxonomy\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class DetachCategory
{
    /**
     * Pivot delete and the MaintainCategoryCounts ancestor walk commit
     * atomically — a failed decrement partway up the chain must not leave the
     * pivot removed with ancestors only partly decremented. See finding B-2.
     */
    public function __invoke(Model&HasCategories $model, Category $category): void
    {
        DB::transaction(function () use ($model, $category): void {
            $removed = $model->categories()->detach($category);

            if ($removed === 0) {
                return;
            }

            CategoryDetached::dispatch($model, $category);
        });
    }
}
