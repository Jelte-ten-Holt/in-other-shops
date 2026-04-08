<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament;

use Filament\Forms\Components\Select;

final class TaxonomySchema
{
    public static function categoriesSelect(string $relationship = 'categories'): Select
    {
        return Select::make($relationship)
            ->relationship($relationship)
            ->getOptionLabelFromRecordUsing(fn ($record) => $record->translated('name'))
            ->multiple()
            ->searchable()
            ->preload();
    }

    public static function tagsSelect(string $relationship = 'tags'): Select
    {
        return Select::make($relationship)
            ->relationship($relationship)
            ->getOptionLabelFromRecordUsing(fn ($record) => $record->translated('name'))
            ->multiple()
            ->searchable()
            ->preload();
    }
}
