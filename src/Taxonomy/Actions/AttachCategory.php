<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Actions;

use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Events\CategoryAttached;
use InOtherShops\Taxonomy\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class AttachCategory
{
    /**
     * The pivot write and the synchronous MaintainCategoryCounts ancestor walk
     * (one upsert per ancestor) must commit atomically. Without the transaction
     * an upsert failing partway — deadlock, lock-wait timeout, connection blip —
     * leaves the pivot row persisted with only some ancestors incremented, and
     * the exception does not roll the pivot back. Drift then accumulates
     * silently until a recompute. See audit finding B-2.
     */
    public function __invoke(Model&HasCategories $model, Category $category): void
    {
        DB::transaction(function () use ($model, $category): void {
            $model->categories()->attach($category);

            CategoryAttached::dispatch($model, $category);
        });
    }
}
