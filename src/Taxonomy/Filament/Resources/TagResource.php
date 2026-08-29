<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources;

use Filament\Actions;
use Filament\Forms\Components\Select;
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
use InOtherShops\Taxonomy\Support\TagTypes;
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
                        static::typeField(),
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

    /**
     * `tags.type` is a free string the package stores and never interprets, so
     * the vocabulary belongs to the project — see `config('taxonomy.tag_types')`
     * and `TagTypes`.
     *
     * A project that declares one gets a select; a project that declares
     * nothing keeps the free-text input it has always had, which is what makes
     * this additive rather than a change every consumer has to react to.
     *
     * The select merges the record's CURRENT value in when the vocabulary does
     * not contain it — a tag typed before the list existed would otherwise show
     * an empty select and lose its type on the next save of any other field.
     */
    private static function typeField(): TextInput|Select
    {
        if (! TagTypes::isConfigured()) {
            return TextInput::make('type')
                ->label(__('shops-common::fields.type'))
                ->maxLength(255)
                ->placeholder(__('shops-taxonomy::tag.fields.type_placeholder'));
        }

        $descriptions = TagTypes::descriptions();

        return Select::make('type')
            ->label(__('shops-common::fields.type'))
            ->options(fn (?Tag $record): array => TagTypes::options($record?->type))
            ->native(false)
            // Nullable by design: `type` has always been optional, and the
            // package's own public-tag filtering treats null as untyped.
            ->placeholder(__('shops-taxonomy::tag.fields.type_placeholder'))
            ->helperText($descriptions === [] ? null : collect($descriptions)
                ->map(fn (string $description, string $value): string => "{$value}: {$description}")
                ->implode(' · '));
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
