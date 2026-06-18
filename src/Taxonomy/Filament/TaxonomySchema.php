<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament;

use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Taxonomy\Actions\SyncCategories;
use InOtherShops\Taxonomy\Contracts\HasCategories;

final class TaxonomySchema
{
    public static function categoriesSelect(string $relationship = 'categories'): Select
    {
        return Select::make($relationship)
            ->relationship($relationship)
            ->getOptionLabelFromRecordUsing(fn ($record) => $record->translated('name') ?? $record->slug)
            ->multiple()
            ->searchable()
            ->preload()
            // Replace Filament's default pivot sync with one that fires
            // CategoryAttached/Detached, so category_morph_counts (the category
            // nav/table-of-contents source) stays maintained. A bare relationship
            // sync writes the pivot directly and silently drifts the counts.
            ->saveRelationshipsUsing(static function (Model $record, mixed $state): void {
                if ($record instanceof HasCategories) {
                    app(SyncCategories::class)($record, is_array($state) ? $state : []);
                }
            });
    }

    public static function tagsSelect(string $relationship = 'tags'): Select
    {
        return Select::make($relationship)
            ->relationship($relationship)
            ->getOptionLabelFromRecordUsing(fn ($record) => $record->translated('name') ?? $record->slug)
            ->multiple()
            ->searchable()
            ->preload();
    }
}
