<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\RelationManagers;

use InOtherShops\Taxonomy\Events\CategoryAttached;
use InOtherShops\Taxonomy\Events\CategoryDetached;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Translation\Filament\TranslationSchema;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'categories';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TranslationSchema::fields(
                    fields: [
                        'name' => TextInput::make('name')->label(__('shops-common::fields.name'))->required()->maxLength(255),
                        'description' => Textarea::make('description')->label(__('shops-common::fields.description')),
                    ],
                    slugSource: 'name',
                    slugTarget: 'slug',
                ),
                TextInput::make('slug')
                    ->label(__('shops-common::fields.slug'))
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('parent_id')
                    ->label(__('shops-taxonomy::category.fields.parent'))
                    ->relationship('parent')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->translated('name'))
                    ->searchable()
                    ->preload()
                    ->placeholder(__('shops-taxonomy::category.fields.parent_placeholder')),
                TextInput::make('position')
                    ->label(__('shops-common::fields.position'))
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
                Toggle::make('is_active')
                    ->label(__('shops-common::fields.active'))
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('shops-common::fields.name'))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereTranslation('name', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByTranslation('name', $direction)),
                Tables\Columns\TextColumn::make('parent.name')
                    ->label(__('shops-taxonomy::category.columns.parent'))
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('shops-common::fields.active'))
                    ->boolean(),
            ])
            ->headerActions([
                Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns([])
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->translated('name'))
                    ->after(fn (Category $record) => CategoryAttached::dispatch($this->getOwnerRecord(), $record)),
                Actions\CreateAction::make()
                    ->after(function ($record, array $data) {
                        TranslationSchema::saveFormData($record, $data);
                    }),
            ])
            ->actions([
                Actions\DetachAction::make()
                    ->after(fn (Category $record) => CategoryDetached::dispatch($this->getOwnerRecord(), $record)),
                Actions\EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, $record) {
                        return array_merge($data, TranslationSchema::fillFormData($record));
                    })
                    ->after(function ($record, array $data) {
                        TranslationSchema::saveFormData($record, $data);
                    }),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    $this->detachBulkAction(),
                ]),
            ]);
    }

    /**
     * Bulk detach must dispatch `CategoryDetached` for every removed record,
     * the same way the single-row `DetachAction` does — otherwise
     * `MaintainCategoryCounts` never decrements `category_morph_counts` and the
     * filter UI keeps reporting detached categories as populated. Filament's
     * stock `DetachBulkAction` fires no event on its own.
     */
    private function detachBulkAction(): Actions\DetachBulkAction
    {
        return Actions\DetachBulkAction::make()
            ->after(fn (Collection $records) => $this->dispatchCategoryDetached($records));
    }

    private function dispatchCategoryDetached(Collection $records): void
    {
        $owner = $this->getOwnerRecord();

        $records->each(fn (Category $record) => CategoryDetached::dispatch($owner, $record));
    }
}
