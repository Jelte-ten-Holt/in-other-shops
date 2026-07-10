<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\RelationManagers;

use InOtherShops\Taxonomy\Events\TagAttached;
use InOtherShops\Taxonomy\Events\TagDetached;
use InOtherShops\Taxonomy\Models\Tag;
use InOtherShops\Translation\Filament\TranslationSchema;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TranslationSchema::fields(
                    fields: [
                        'name' => TextInput::make('name')->label(__('shops-common::fields.name'))->required()->maxLength(255),
                    ],
                    slugSource: 'name',
                    slugTarget: 'slug',
                ),
                TextInput::make('slug')
                    ->label(__('shops-common::fields.slug'))
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('type')
                    ->label(__('shops-common::fields.type'))
                    ->maxLength(255)
                    ->placeholder(__('shops-taxonomy::tag.fields.type_placeholder')),
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
                Tables\Columns\TextColumn::make('type')
                    ->label(__('shops-common::fields.type'))
                    ->badge()
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
                    ->after(fn (Tag $record) => TagAttached::dispatch($this->getOwnerRecord(), $record)),
                Actions\CreateAction::make()
                    ->after(function ($record, array $data) {
                        TranslationSchema::saveFormData($record, $data);
                    }),
            ])
            ->actions([
                Actions\DetachAction::make()
                    ->after(fn (Tag $record) => TagDetached::dispatch($this->getOwnerRecord(), $record)),
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
                    Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
