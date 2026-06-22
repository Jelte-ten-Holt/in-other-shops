<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Taxonomy\Taxonomy;

/**
 * Lists everything attached to a category *and its descendants* — products,
 * bundles, content, or any other categorizable — grouped by morph type and
 * labelled for display. Rolling the subtree up matches how a hierarchical
 * taxonomy reads: opening a parent shows the content filed under its children,
 * not an empty list (the same ancestor-rollup the storefront counts use).
 *
 * Reads the categorizables pivot directly and resolves each type through the
 * morph map, so the Taxonomy domain stays decoupled from the concrete consumer
 * models it categorizes. Labels come from a generic attribute fallback
 * (name → title → "{type} #id"); deliberately NOT from Storefront's
 * HasStorefrontPresence, which would introduce a Taxonomy→Storefront
 * dependency cycle (Storefront already depends on Taxonomy).
 *
 * Each entry carries `filedUnder` — the descendant category names an item is
 * attached to (null when it's attached directly to the viewed category) — so a
 * deep subtree stays legible. URL resolution is left to the presentation layer.
 */
final class ListCategoryAttachments
{
    /**
     * @return array<string, list<array{id: int, label: string, filedUnder: ?string}>>
     *         morph alias => items (label A→Z), the map keyed alias-ascending
     */
    public function __invoke(Category $category): array
    {
        $subtree = $this->subtree($category);

        $categoryIdsByItem = $this->attachmentsWithin(array_keys($subtree));

        $rootId = (int) $category->getKey();

        $grouped = [];

        foreach ($categoryIdsByItem as $type => $itemMap) {
            $grouped[$type] = $this->entriesFor($type, $itemMap, $rootId, $subtree);
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * The viewed category plus every descendant, mapped to its display name.
     *
     * @return array<int, string>  category id => name (slug fallback)
     */
    private function subtree(Category $category): array
    {
        /** @var class-string<Category> $model */
        $model = Taxonomy::category();

        $all = $model::query()->with('translations')->get();

        $childrenByParent = [];
        $nameById = [];

        foreach ($all as $node) {
            $childrenByParent[$node->parent_id ?? 0][] = (int) $node->id;
            $nameById[(int) $node->id] = $this->displayName($node);
        }

        $subtree = [];
        $stack = [(int) $category->getKey()];

        while ($stack !== []) {
            $id = array_pop($stack);

            if (isset($subtree[$id])) {
                continue;
            }

            $subtree[$id] = $nameById[$id] ?? '';

            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return $subtree;
    }

    private function displayName(Category $category): string
    {
        $name = $category->translated('name');

        return ($name !== null && $name !== '') ? $name : (string) $category->slug;
    }

    /**
     * @param  list<int>  $categoryIds
     * @return array<string, array<int, list<int>>>  type => item id => attached-category ids
     */
    private function attachmentsWithin(array $categoryIds): array
    {
        $rows = DB::table('categorizables')
            ->whereIn('category_id', $categoryIds)
            ->get(['category_id', 'categorizable_type', 'categorizable_id']);

        $byType = [];

        foreach ($rows as $row) {
            $byType[$row->categorizable_type][(int) $row->categorizable_id][] = (int) $row->category_id;
        }

        return $byType;
    }

    /**
     * @param  array<int, list<int>>  $itemMap  item id => attached-category ids
     * @param  array<int, string>  $subtree
     * @return list<array{id: int, label: string, filedUnder: ?string}>
     */
    private function entriesFor(string $type, array $itemMap, int $rootId, array $subtree): array
    {
        $labels = $this->labelsById($type, array_keys($itemMap));

        $entries = [];

        foreach ($itemMap as $itemId => $categoryIds) {
            $entries[] = [
                'id' => $itemId,
                'label' => $labels[$itemId] ?? $this->fallbackLabel($type, $itemId),
                'filedUnder' => $this->filedUnder($categoryIds, $rootId, $subtree),
            ];
        }

        usort($entries, fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $entries;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>  item id => label
     */
    private function labelsById(string $type, array $ids): array
    {
        $class = $this->resolveModelClass($type);

        if ($class === null) {
            return [];
        }

        $instance = new $class;

        return $class::query()
            ->whereIn($instance->getKeyName(), $ids)
            ->get()
            ->mapWithKeys(fn (Model $model): array => [(int) $model->getKey() => $this->labelFor($model)])
            ->all();
    }

    /**
     * @param  list<int>  $categoryIds
     * @param  array<int, string>  $subtree
     */
    private function filedUnder(array $categoryIds, int $rootId, array $subtree): ?string
    {
        $names = [];

        foreach (array_unique($categoryIds) as $categoryId) {
            if ($categoryId === $rootId) {
                continue;
            }

            $name = $subtree[$categoryId] ?? '';

            if ($name !== '') {
                $names[$name] = true;
            }
        }

        if ($names === []) {
            return null;
        }

        $names = array_keys($names);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return implode(', ', $names);
    }

    /** @return class-string<Model>|null */
    private function resolveModelClass(string $type): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        return is_a($class, Model::class, true) ? $class : null;
    }

    private function labelFor(Model $model): string
    {
        foreach (['name', 'title'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return $this->fallbackLabel($model->getMorphClass(), (int) $model->getKey());
    }

    private function fallbackLabel(string $type, int $id): string
    {
        return Str::headline($type).' #'.$id;
    }
}
