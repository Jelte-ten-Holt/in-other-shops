<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources;

use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Taxonomy\Filament\Resources\CategoryResource\Pages;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Translation\Filament\TranslationSchema;

final class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static string|\UnitEnum|null $navigationGroup = 'Taxonomy';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Category Details')
                    ->schema([
                        TranslationSchema::fields(
                            fields: [
                                'name' => TextInput::make('name')->required()->maxLength(255),
                                'description' => Textarea::make('description'),
                            ],
                            slugSource: 'name',
                            slugTarget: 'slug',
                        ),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('parent_id')
                            ->label('Parent Category')
                            ->relationship('parent')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->translated('name'))
                            ->searchable()
                            ->preload()
                            ->placeholder('None (root category)'),
                        TextInput::make('position')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Select::make('tags')
                            ->label('Tags')
                            ->relationship('tags')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->translated('name'))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Flags for this category — e.g. a "featured" tag to surface it on the storefront home page.'),
                    ]),
                Section::make('Cover Image')
                    ->schema([
                        MediaSchema::mediaRepeater('images')
                            ->label('Cover image')
                            ->helperText('Shown on category teasers and listings.')
                            ->maxItems(1),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereTranslation('name', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByTranslation('name', $direction)),
                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Parent')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('position')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('position')
            ->reorderable('position')
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->disabled(fn (Category $record): bool => $record->children()->exists())
                    ->modalDescription(fn (Category $record): string => self::deleteModalDescription($record)),
            ]);
    }

    private static function deleteModalDescription(Category $record): string
    {
        $attached = (int) DB::table('categorizables')
            ->where('category_id', $record->getKey())
            ->count();

        if ($attached === 0) {
            return 'Are you sure you want to delete this category? This action cannot be undone.';
        }

        $noun = $attached === 1 ? 'item is' : 'items are';

        return "{$attached} {$noun} attached to this category. Deleting it will detach them; the items themselves are not deleted. Are you sure?";
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
