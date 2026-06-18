<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Taxonomy;

/**
 * Replace a model's categories with the given set, routing every add and remove
 * through AttachCategory/DetachCategory so CategoryAttached/CategoryDetached
 * fire and MaintainCategoryCounts keeps category_morph_counts current.
 *
 * Use this anywhere a raw `->categories()->sync()` would otherwise write the
 * pivot directly — a bare sync bypasses both events and silently drifts the
 * subtree counts that the category nav/table-of-contents reads. The whole
 * set-change commits atomically (the per-category actions nest as savepoints).
 */
final class SyncCategories
{
    public function __construct(
        private readonly AttachCategory $attach,
        private readonly DetachCategory $detach,
    ) {}

    /**
     * @param  array<int, int|string>  $categoryIds
     */
    public function __invoke(Model&HasCategories $model, array $categoryIds): void
    {
        $newIds = collect($categoryIds)
            ->filter(static fn ($id): bool => $id !== null && $id !== '')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->all();

        $relation = $model->categories();
        $currentIds = $relation->pluck($relation->getModel()->getQualifiedKeyName())
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $toAttach = array_values(array_diff($newIds, $currentIds));
        $toDetach = array_values(array_diff($currentIds, $newIds));

        if ($toAttach === [] && $toDetach === []) {
            return;
        }

        $categoryModel = Taxonomy::category();
        $categories = $categoryModel::query()
            ->findMany(array_merge($toAttach, $toDetach))
            ->keyBy(static fn (Model $category): int => (int) $category->getKey());

        DB::transaction(function () use ($model, $categories, $toAttach, $toDetach): void {
            foreach ($toAttach as $id) {
                if ($category = $categories->get($id)) {
                    ($this->attach)($model, $category);
                }
            }

            foreach ($toDetach as $id) {
                if ($category = $categories->get($id)) {
                    ($this->detach)($model, $category);
                }
            }
        });
    }
}
