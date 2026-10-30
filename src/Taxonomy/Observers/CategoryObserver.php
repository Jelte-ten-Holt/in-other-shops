<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Observers;

use Illuminate\Support\Facades\DB;
use InOtherShops\Taxonomy\Events\CategoryDeleted;
use InOtherShops\Taxonomy\Events\CategoryMoved;
use InOtherShops\Taxonomy\Exceptions\CategoryCycleException;
use InOtherShops\Taxonomy\Exceptions\CategoryHasChildrenException;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Taxonomy\Support\CategoryAncestry;

/**
 * Translates Eloquent lifecycle into the domain events that
 * MaintainCategoryCounts uses to keep the subtree-counts table accurate.
 *
 * Move: fires after save once parent_id has actually changed, carrying both
 * the original and new parent so the listener can shift the subtree counts
 * from the old ancestor chain to the new one.
 *
 * Cycle: refused before the update is written. A category moved under itself or
 * one of its own descendants would make the tree loop; consumers walk it
 * recursively (a category page's subtree), so the guard sits on the model where
 * the admin, the agent tools and tinker all meet it.
 *
 * Delete: fires before delete because the row's counts are read off the
 * counts table, which cascades away the moment the category row is gone.
 * The snapshot rides along on the event so the listener has everything it
 * needs to decrement the old ancestors with no further DB reads.
 */
final class CategoryObserver
{
    public function updating(Category $category): void
    {
        if (! $category->isDirty('parent_id') || $category->parent_id === null) {
            return;
        }

        $this->refuseCycle($category, (int) $category->parent_id);
    }

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

    /**
     * Walk up from the new parent over the stored tree. Reaching the category
     * itself means the new parent is the category or sits beneath it. The
     * category's own row still holds its old parent here, so the walk sees the
     * tree as it is before the move — which is the tree the move would close.
     */
    private function refuseCycle(Category $category, int $parentId): void
    {
        $self = (int) $category->getKey();
        $loops = false;

        CategoryAncestry::walkUp($parentId, CategoryAncestry::parentMap(), function (int $id) use ($self, &$loops): void {
            if ($id === $self) {
                $loops = true;
            }
        });

        if ($loops) {
            throw CategoryCycleException::for($category, $parentId);
        }
    }
}
