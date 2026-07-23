<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources;

use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use InOtherShops\Support\Filament\NavigationGroup;
use InOtherShops\Support\Filament\PackageResource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InOtherShops\Taxonomy\Filament\Resources\TagResource\Pages;
use InOtherShops\Taxonomy\Models\Tag;
use InOtherShops\Translation\Filament\TranslationSchema;

final class TagResource extends PackageResource
{
    protected static ?string $model = Tag::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Taxonomy;

    protected static function labelKey(): string
    {
        return 'shops-taxonomy::tag';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('shops-taxonomy::tag.section.details'))
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
                        TextInput::make('position')
                            ->label(__('shops-common::fields.position'))
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Toggle::make('is_active')
                            ->label(__('shops-common::fields.active'))
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
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
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('position')
                    ->label(__('shops-common::fields.position'))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('shops-common::fields.active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('shops-common::fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('position')
            ->reorderable('position')
            ->actions([
                Actions\EditAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTags::route('/'),
            'create' => Pages\CreateTag::route('/create'),
            'edit' => Pages\EditTag::route('/{record}/edit'),
        ];
    }
}
