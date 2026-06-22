<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InOtherShops\Taxonomy\Models\Category;

/**
 * Lists everything attached to a category — products, bundles, content, or any
 * other categorizable — grouped by morph type and labelled for display.
 *
 * Reads the categorizables pivot directly and resolves each type through the
 * morph map, so the Taxonomy domain stays decoupled from the concrete consumer
 * models it categorizes. Labels come from a generic attribute fallback
 * (name → title → "{type} #id"); deliberately NOT from Storefront's
 * HasStorefrontPresence, which would introduce a Taxonomy→Storefront
 * dependency cycle (Storefront already depends on Taxonomy).
 */
final class ListCategoryAttachments
{
    /**
     * @return array<string, list<string>>  morph alias => display labels (A→Z), the map keyed alias-ascending
     */
    public function __invoke(Category $category): array
    {
        $idsByType = $this->attachmentIdsByType($category);

        $labelsByType = [];

        foreach ($idsByType as $type => $ids) {
            $labelsByType[$type] = $this->labelsFor($type, $ids);
        }

        ksort($labelsByType);

        return $labelsByType;
    }

    /** @return array<string, list<int>> */
    private function attachmentIdsByType(Category $category): array
    {
        $rows = DB::table('categorizables')
            ->where('category_id', $category->getKey())
            ->get(['categorizable_type', 'categorizable_id']);

        $idsByType = [];

        foreach ($rows as $row) {
            $idsByType[$row->categorizable_type][] = (int) $row->categorizable_id;
        }

        return $idsByType;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function labelsFor(string $type, array $ids): array
    {
        $class = $this->resolveModelClass($type);

        if ($class === null) {
            $labels = array_map(fn (int $id): string => $this->fallbackLabel($type, $id), $ids);
        } else {
            $instance = new $class;

            $labels = $class::query()
                ->whereIn($instance->getKeyName(), $ids)
                ->get()
                ->map(fn (Model $model): string => $this->labelFor($model))
                ->all();
        }

        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($labels);
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
