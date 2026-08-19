<?php

declare(strict_types=1);

namespace InOtherShops\Media\Filament\RelationManagers;

use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Media\Models\Media;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('shops-media::media_relation.title');
    }

    public function form(Schema $schema): Schema
    {
        $disk = config('media.disk');
        $directory = config('media.directory');
        $collections = config('media.collections', []);
        $collectionOptions = array_map(
            fn (array $config) => __($config['label']),
            $collections,
        );

        return $schema
            ->schema([
                FileUpload::make('path')
                    ->label(__('shops-media::media_relation.fields.path'))
                    ->required()
                    ->disk($disk)
                    ->directory($directory)
                    ->visibility('public')
                    ->acceptedFileTypes(MediaSchema::allowedUploadMimeTypes())
                    ->maxSize(10240)
                    ->columnSpanFull(),
                Select::make('collection')
                    ->label(__('shops-media::media_relation.fields.collection'))
                    ->options($collectionOptions)
                    ->required(),
                ...self::translatableTextInputs(),
            ]);
    }

    /**
     * Alt and description, one pair per configured locale — the same shape
     * MediaSchema's repeater uses, for the same reason: both are prose a reader
     * sees, so they live in `translations`, not in a column.
     *
     * @return list<TextInput|Textarea>
     */
    private static function translatableTextInputs(): array
    {
        $locales = config('translation.locales', ['en']);
        $locales = is_array($locales) && $locales !== [] ? $locales : ['en'];
        $multi = count($locales) > 1;

        $fields = [];

        foreach ($locales as $locale) {
            $suffix = $multi ? ' ('.strtoupper($locale).')' : '';

            $fields[] = TextInput::make("_text.alt.{$locale}")
                ->label(__('shops-media::media_relation.fields.alt').$suffix)
                ->maxLength(255);

            $fields[] = Textarea::make("_text.description.{$locale}")
                ->label(__('shops-media::media_relation.fields.description').$suffix)
                ->rows(2)
                ->columnSpanFull();
        }

        return $fields;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('path')
                    ->label(__('shops-media::media_relation.columns.thumbnail'))
                    ->disk(fn ($record) => $record->disk)
                    ->square()
                    ->size(40),
                Tables\Columns\TextColumn::make('filename')
                    ->label(__('shops-media::media_relation.columns.filename'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('pivot.collection')
                    ->label(__('shops-media::media_relation.columns.collection'))
                    ->badge()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('size')
                    ->label(__('shops-media::media_relation.columns.size'))
                    ->formatStateUsing(fn (int $state) => Number::fileSize($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('alt')
                    ->label(__('shops-media::media_relation.columns.alt'))
                    ->placeholder('—'),
            ])
            ->defaultSort('mediables.position')
            ->reorderable('mediables.position')
            ->headerActions([
                Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        return $this->enrichFormData($data);
                    })
                    ->after(function (Media $record, array $data): void {
                        $this->syncText($record, ['_text' => $this->stagedText]);
                        $this->stagedText = [];

                        $this->getOwnerRecord()->media()->attach($record->id, [
                            'collection' => $data['collection'] ?? '',
                            'position' => 0,
                        ]);
                    }),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, Media $record): array => $this->fillText($data, $record))
                    ->mutateFormDataUsing(function (array $data): array {
                        // `_text` is not a column; it is written after the save.
                        $this->stagedText = $data['_text'] ?? [];
                        unset($data['_text']);

                        return $data;
                    })
                    ->after(function (Media $record): void {
                        $this->syncText($record, ['_text' => $this->stagedText]);
                        $this->stagedText = [];
                    }),
                Actions\DetachAction::make()
                    ->after(function (Media $record): void {
                        $record->delete();
                    }),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DetachBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Per-locale text captured between the form save and the after-hook, where
     * the record exists and its translation rows can be written.
     *
     * @var array<string, array<string, string>>
     */
    private array $stagedText = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillText(array $data, Media $record): array
    {
        $record->loadMissing('translations');

        foreach (['alt', 'description'] as $field) {
            foreach ($this->formLocales() as $locale) {
                $data['_text'][$field][$locale] = $record->translations
                    ->where('locale', $locale)
                    ->where('field', $field)
                    ->first()
                    ?->value ?? '';
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncText(Media $record, array $data): void
    {
        $text = $data['_text'] ?? [];

        foreach (['alt', 'description'] as $field) {
            foreach ($this->formLocales() as $locale) {
                $value = $text[$field][$locale] ?? null;

                if ($value === null || $value === '') {
                    $record->translations()
                        ->where('locale', $locale)
                        ->where('field', $field)
                        ->delete();

                    continue;
                }

                $record->setTranslation($field, $locale, (string) $value);
            }
        }

        $record->unsetRelation('translations');
    }

    /** @return list<string> */
    private function formLocales(): array
    {
        $locales = config('translation.locales', ['en']);

        return is_array($locales) && $locales !== [] ? array_values($locales) : ['en'];
    }

    private function enrichFormData(array $data): array
    {
        // `_text` is not a column; stage it for the after-hook, where the
        // record exists and its translation rows can be written.
        $this->stagedText = $data['_text'] ?? [];
        unset($data['_text']);

        $disk = config('media.disk');
        $data['disk'] = $disk;
        $data['type'] = MediaType::Upload;

        if (isset($data['path'])) {
            $storage = Storage::disk($disk);
            $data['filename'] = basename($data['path']);
            $data['mime_type'] = $storage->mimeType($data['path']) ?: 'application/octet-stream';
            $data['size'] = $storage->size($data['path']) ?: 0;
        }

        return $data;
    }
}
