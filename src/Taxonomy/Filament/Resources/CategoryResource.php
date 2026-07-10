<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources;

use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use InOtherShops\Support\Filament\NavigationGroup;
use InOtherShops\Support\Filament\PackageResource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Taxonomy\Actions\ListCategoriesInTreeOrder;
use InOtherShops\Taxonomy\Actions\ListCategoryAttachments;
use InOtherShops\Taxonomy\Filament\Resources\CategoryResource\Pages;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Translation\Filament\TranslationSchema;

final class CategoryResource extends PackageResource
{
    protected static ?string $model = Category::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Taxonomy;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('shops-taxonomy::category.section.details'))
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
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->translated('name') ?? $record->slug)
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
                        Select::make('tags')
                            ->label(__('shops-taxonomy::category.fields.tags'))
                            ->relationship('tags')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->translated('name') ?? $record->slug)
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText(__('shops-taxonomy::category.fields.tags_help')),
                    ]),
                Section::make(__('shops-taxonomy::category.section.cover_image'))
                    ->schema([
                        MediaSchema::mediaRepeater('images')
                            ->label(__('shops-taxonomy::category.fields.cover_image'))
                            ->helperText(__('shops-taxonomy::category.fields.cover_image_help'))
                            ->maxItems(1),
                    ]),
                Section::make(__('shops-taxonomy::category.section.assigned_items'))
                    ->description(__('shops-taxonomy::category.section.assigned_items_description'))
                    ->hiddenOn('create')
                    ->schema([
                        Placeholder::make('assigned_items')
                            ->hiddenLabel()
                            ->content(fn (Category $record): HtmlString|string => self::renderAssignedItems($record)),
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
                Tables\Columns\TextColumn::make('parent.name')
                    ->label(__('shops-taxonomy::category.columns.parent'))
                    ->placeholder('—')
                    ->sortable(),
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
            ->defaultSort(fn (Builder $query): Builder => self::applyTreeOrder($query))
            ->reorderable('position')
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->disabled(fn (Category $record): bool => $record->children()->exists())
                    ->modalDescription(fn (Category $record): string => self::deleteModalDescription($record)),
            ]);
    }

    /**
     * Orders the index depth-first so each parent is immediately followed by
     * its children. Applied as the table's default sort, so a column-header
     * click still takes precedence (Filament adds the column sort first and
     * this only tie-breaks) and drag-reorder bypasses it entirely.
     */
    private static function applyTreeOrder(Builder $query): Builder
    {
        $orderedIds = (new ListCategoriesInTreeOrder)();

        if ($orderedIds === []) {
            return $query;
        }

        $whens = [];
        foreach ($orderedIds as $index => $id) {
            $whens[] = "WHEN {$id} THEN {$index}";
        }

        $key = $query->getModel()->getQualifiedKeyName();

        return $query->orderByRaw('CASE '.$key.' '.implode(' ', $whens).' END');
    }

    private static function renderAssignedItems(Category $record): HtmlString|string
    {
        $grouped = (new ListCategoryAttachments)($record);

        if ($grouped === []) {
            return __('shops-taxonomy::category.assigned.empty');
        }

        $sections = '';

        foreach ($grouped as $type => $items) {
            $heading = e(Str::headline($type)).' ('.count($items).')';

            $listItems = implode('', array_map(
                static fn (array $item): string => '<li>'.self::renderAssignedItem($type, $item).'</li>',
                $items,
            ));

            $sections .= '<div class="mb-4">'
                .'<p class="text-sm font-semibold text-gray-950 dark:text-white">'.$heading.'</p>'
                .'<ul class="mt-1 list-disc ps-5 text-sm text-gray-600 dark:text-gray-400">'.$listItems.'</ul>'
                .'</div>';
        }

        return new HtmlString($sections);
    }

    /**
     * @param  array{id: int, label: string, filedUnder: ?string}  $item
     */
    private static function renderAssignedItem(string $type, array $item): string
    {
        $label = e($item['label']);
        $url = self::resolveEditUrl($type, $item['id']);

        $rendered = $url !== null
            ? '<a href="'.e($url).'" class="text-primary-600 hover:underline dark:text-primary-400">'.$label.'</a>'
            : $label;

        if ($item['filedUnder'] !== null) {
            $rendered .= ' <span class="text-gray-400 dark:text-gray-500">— '.e($item['filedUnder']).'</span>';
        }

        return $rendered;
    }

    /**
     * Resolves the admin edit URL for an attached item by asking the panel which
     * resource manages its model — so the package links to consumer-defined
     * resources (Product/Content/Bundle) without importing them. Falls back to
     * plain text when no resource, no edit page, or the URL can't be built.
     */
    private static function resolveEditUrl(string $type, int $id): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_a($class, Model::class, true)) {
            return null;
        }

        try {
            $resource = Filament::getModelResource($class);

            if ($resource === null || ! $resource::hasPage('edit')) {
                return null;
            }

            return $resource::getUrl('edit', ['record' => $id]);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function deleteModalDescription(Category $record): string
    {
        $attached = (int) DB::table('categorizables')
            ->where('category_id', $record->getKey())
            ->count();

        if ($attached === 0) {
            return __('shops-taxonomy::category.delete.confirm');
        }

        $noun = $attached === 1 ? 'item is' : 'items are';

        return "{$attached} {$noun} attached to this category. Deleting it will detach them; the items themselves are not deleted. Are you sure?";
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
