<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Filament\Resources;

use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use InOtherShops\Support\Filament\NavigationGroup;
use InOtherShops\Support\Filament\PackageResource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Models\Media;
use InOtherShops\Translation\Filament\TranslationSchema;
use InOtherShops\Variants\Filament\Resources\OptionResource\Pages;
use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\OptionValue;
use InOtherShops\Variants\Variants;

/**
 * Standalone admin for the global Option catalog (Metal, Ring Size, …) and each
 * option's ordered values. Option name and value labels are translatable; slug
 * and value code are the stable identifiers.
 *
 * Values are managed via a manual-sync repeater (`_values`) — see
 * {@see self::fillValues()} / {@see self::saveValues()}, wired from the pages.
 */
final class OptionResource extends PackageResource
{
    protected static ?string $model = Option::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Variants;

    protected static function labelKey(): string
    {
        return 'shops-variants::option';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('shops-variants::option.section.option'))
                    ->schema([
                        TranslationSchema::fields(
                            fields: ['name' => TextInput::make('name')->label(__('shops-common::fields.name'))->required()->maxLength(255)],
                            slugSource: 'name',
                            slugTarget: 'slug',
                        ),
                        TextInput::make('slug')
                            ->label(__('shops-common::fields.slug'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('position')
                            ->label(__('shops-common::fields.position'))
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ]),
                Section::make(__('shops-variants::option.section.values'))
                    ->schema([self::valuesRepeater()]),
            ]);
    }

    public static function valuesRepeater(): Repeater
    {
        return Repeater::make('_values')
            ->label(__('shops-variants::option.repeater.values_label'))
            ->orderColumn('position')
            ->schema([
                ...self::labelInputs(),
                TextInput::make('value')
                    ->label(__('shops-common::fields.code'))
                    ->required()
                    ->maxLength(255)
                    ->helperText(__('shops-variants::option.value.code_help')),
                FileUpload::make('swatch')
                    ->label(__('shops-variants::option.value.swatch_label'))
                    ->image()
                    ->disk(config('media.disk'))
                    ->directory(config('media.directory'))
                    ->visibility('public')
                    ->helperText(__('shops-variants::option.value.swatch_help')),
            ])
            ->columns(2)
            ->defaultItems(0);
    }

    /** @return array<int, TextInput> One label input per configured locale. */
    private static function labelInputs(): array
    {
        $default = config('translation.default', 'en');

        return array_map(
            fn (string $locale): TextInput => TextInput::make("labels.{$locale}")
                ->label(__('shops-variants::option.value.label', ['locale' => strtoupper($locale)]))
                ->required($locale === $default)
                ->maxLength(255),
            config('translation.locales', ['en']),
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('shops-common::fields.name'))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereTranslation('name', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByTranslation('name', $direction)),
                Tables\Columns\TextColumn::make('slug')
                    ->label(__('shops-common::fields.slug'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('values_count')
                    ->label(__('shops-variants::option.column.values'))
                    ->counts('values'),
                Tables\Columns\TextColumn::make('position')
                    ->label(__('shops-common::fields.position'))
                    ->sortable(),
            ])
            ->defaultSort('position')
            ->reorderable('position')
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ]);
    }

    /**
     * Load the option's values (with per-locale labels) into repeater state.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillValues(Option $record, array $data): array
    {
        $locales = config('translation.locales', ['en']);

        $record->load('values.translations');

        $data['_values'] = $record->values
            ->map(fn (OptionValue $value): array => [
                'id' => $value->id,
                'value' => $value->value,
                'position' => $value->position,
                'swatch' => $value->swatch()?->path,
                'labels' => collect($locales)
                    ->mapWithKeys(fn (string $locale): array => [
                        $locale => $value->translations
                            ->where('locale', $locale)
                            ->where('field', 'label')
                            ->first()?->value ?? '',
                    ])
                    ->all(),
            ])
            ->all();

        return $data;
    }

    /**
     * Persist repeater state back to the option's values: upsert present rows,
     * delete removed ones, and sync each value's label translations.
     *
     * @param  array<string, mixed>  $data
     */
    public static function saveValues(Option $record, array $data): void
    {
        $rows = $data['_values'] ?? [];
        $keptIds = [];

        foreach (array_values($rows) as $position => $row) {
            $attributes = ['value' => $row['value'], 'position' => $position];

            if (! empty($row['id'])) {
                $value = $record->values()->findOrFail($row['id']);
                $value->update($attributes);
            } else {
                $value = $record->values()->create($attributes);
            }

            self::syncValueLabels($value, $row['labels'] ?? []);
            self::syncValueSwatch($value, $row['swatch'] ?? null);
            $keptIds[] = $value->id;
        }

        $record->values()->whereNotIn('id', $keptIds)->each(function (OptionValue $value): void {
            self::syncValueSwatch($value, null);
            $value->delete();
        });
    }

    /**
     * Sync the value's single swatch image to the uploaded path: no-op when
     * unchanged, replace (deleting the old file) when changed, remove when
     * cleared. The Filament FileUpload has already stored the file, so we build
     * the Media record from the stored path (mirroring MediaSchema).
     */
    private static function syncValueSwatch(OptionValue $value, ?string $path): void
    {
        $newPath = is_string($path) && $path !== '' ? $path : null;
        $current = $value->swatch();

        if ($newPath === $current?->path) {
            return;
        }

        if ($current !== null) {
            $value->media()->detach($current->id);
            $current->delete();
        }

        if ($newPath === null) {
            return;
        }

        $disk = config('media.disk');
        $storage = Storage::disk($disk);

        $media = Media::create([
            'type' => MediaType::Upload,
            'disk' => $disk,
            'path' => $newPath,
            'filename' => basename($newPath),
            'mime_type' => $storage->mimeType($newPath) ?: 'application/octet-stream',
            'size' => $storage->size($newPath) ?: 0,
        ]);

        $value->media()->attach($media->id, [
            'collection' => OptionValue::SWATCH_COLLECTION,
            'position' => 0,
        ]);
    }

    /** @param array<string, string> $labels */
    private static function syncValueLabels(OptionValue $value, array $labels): void
    {
        foreach ($labels as $locale => $label) {
            if ($label === '' || $label === null) {
                $value->translations()->where('locale', $locale)->where('field', 'label')->delete();

                continue;
            }

            $value->setTranslation('label', $locale, $label);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOptions::route('/'),
            'create' => Pages\CreateOption::route('/create'),
            'edit' => Pages\EditOption::route('/{record}/edit'),
        ];
    }
}
