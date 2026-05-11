<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Observers;

use Illuminate\Support\Facades\DB;
use InOtherShops\Taxonomy\Events\CategoryDeleted;
use InOtherShops\Taxonomy\Events\CategoryMoved;
use InOtherShops\Taxonomy\Exceptions\CategoryHasChildrenException;
use InOtherShops\Taxonomy\Models\Category;

/**
 * Translates Eloquent lifecycle into the domain events that
 * MaintainCategoryCounts uses to keep the subtree-counts table accurate.
 *
 * Move: fires after save once parent_id has actually changed, carrying both
 * the original and new parent so the listener can shift the subtree counts
 * from the old ancestor chain to the new one.
 *
 * Delete: fires before delete because the row's counts are read off the
 * counts table, which cascades away the moment the category row is gone.
 * The snapshot rides along on the event so the listener has everything it
 * needs to decrement the old ancestors with no further DB reads.
 */
final class CategoryObserver
{
    public function updated(Category $category): void
    {
        if (! $category->wasChanged('parent_id')) {
            return;
        }

        $original = $category->getOriginal('parent_id');
        $current = $category->parent_id;

        CategoryMoved::dispatch(
            $category,
            $original === null ? null : (int) $original,
            $current === null ? null : (int) $current,
        );
    }

    public function deleting(Category $category): void
    {
        // Guard before any dispatch: the DB has restrictOnDelete on
        // parent_id, but the FK check runs after the deleting event. If
        // we dispatched first, a refused delete would still have
        // decremented ancestors' counts. Throwing here cancels the delete
        // outright — listeners never see a CategoryDeleted that didn't
        // happen.
        if ($category->children()->exists()) {
            throw CategoryHasChildrenException::for($category);
        }

        $counts = DB::table('category_morph_counts')
            ->where('category_id', $category->getKey())
            ->pluck('count', 'morph_alias')
            ->map(fn ($value) => (int) $value)
            ->all();

        $parent = $category->parent_id;

        CategoryDeleted::dispatch(
            (int) $category->getKey(),
            $parent === null ? null : (int) $parent,
            $counts,
        );
    }
}
