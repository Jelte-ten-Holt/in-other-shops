<?php

declare(strict_types=1);

namespace InOtherShops\Media\Filament;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Html;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use InOtherShops\Media\Contracts\HasMedia;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Models\Media;
use InvalidArgumentException;

final class MediaSchema
{
    public static function mediaRepeater(string $collection): Repeater
    {
        self::validateCollection($collection);

        $disk = config('media.disk');
        $directory = config('media.directory');
        $label = self::collectionLabel($collection);

        return Repeater::make("_media.{$collection}")
            ->label($label)
            ->defaultItems(0)
            ->schema([
                Hidden::make('media_id'),
                Select::make('type')
                    ->options([
                        MediaType::Upload->value => 'Upload',
                        MediaType::External->value => 'External URL',
                        MediaType::Embed->value => 'Embed',
                    ])
                    ->default(MediaType::Upload->value)
                    ->required()
                    ->live()
                    ->columnSpanFull(),
                FileUpload::make('path')
                    ->required()
                    ->disk($disk)
                    ->directory($directory)
                    ->visibility('public')
                    ->columnSpanFull()
                    ->visible(fn ($get) => $get('type') === MediaType::Upload->value),
                TextInput::make('url')
                    ->label('URL')
                    ->required()
                    ->url()
                    ->live(onBlur: true)
                    ->columnSpanFull()
                    ->visible(fn ($get) => in_array($get('type'), [MediaType::External->value, MediaType::Embed->value], true)),
                Html::make(fn ($get) => new HtmlString(
                    '<img src="'.e($get('url')).'" style="max-height: 150px; border-radius: 0.5rem;" />',
                ))
                    ->visible(fn ($get) => $get('type') === MediaType::External->value && filled($get('url')))
                    ->columnSpanFull(),
                Html::make(fn ($get) => self::embedPreview($get('url')))
                    ->visible(fn ($get) => $get('type') === MediaType::Embed->value && filled($get('url')))
                    ->columnSpanFull(),
                TextInput::make('alt')
                    ->maxLength(255),
                Toggle::make('is_cover')
                    ->label('Use as cover image')
                    ->helperText('The cover image is used in listings and social previews. Only one row across all media collections is kept as the cover.')
                    ->default(false),
            ])
            ->columns(1)
            ->reorderable()
            ->collapsible();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillFormData(Model&HasMedia $record, array $data): array
    {
        $record->load('media');

        $collections = array_keys(self::collections());

        foreach ($collections as $collection) {
            $items = $record->media
                ->filter(fn (Media $media) => $media->pivot->collection === $collection)
                ->sortBy(fn (Media $media) => $media->pivot->position)
                ->values()
                ->map(fn (Media $media) => [
                    'media_id' => $media->id,
                    'type' => $media->type->value,
                    'path' => $media->path,
                    'url' => $media->type !== MediaType::Upload ? $media->getAttribute('url') : null,
                    'alt' => $media->alt,
                    'is_cover' => (bool) $media->pivot->is_cover,
                ])
                ->all();

            $data['_media'][$collection] = $items;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveFormData(Model&HasMedia $record, array $data): void
    {
        $mediaData = self::normalizeSingleCover($data['_media'] ?? []);

        foreach ($mediaData as $collection => $items) {
            self::syncCollection($record, $collection, $items ?? []);
        }

        $record->unsetRelation('media');
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $mediaData
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function normalizeSingleCover(array $mediaData): array
    {
        $coverClaimed = false;

        foreach ($mediaData as $collection => $items) {
            foreach ($items ?? [] as $index => $item) {
                $isCover = ! empty($item['is_cover']);

                if ($isCover && ! $coverClaimed) {
                    $mediaData[$collection][$index]['is_cover'] = true;
                    $coverClaimed = true;
                } else {
                    $mediaData[$collection][$index]['is_cover'] = false;
                }
            }
        }

        return $mediaData;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function collections(): array
    {
        return config('media.collections', []);
    }

    private static function syncCollection(Model&HasMedia $record, string $collection, array $items): void
    {
        $existingIds = $record->media()
            ->wherePivot('collection', $collection)
            ->pluck('media.id')
            ->all();

        $keptIds = [];

        foreach (array_values($items) as $position => $item) {
            if (! empty($item['media_id'])) {
                self::updateExistingMedia($record, (int) $item['media_id'], $collection, $position, $item);
                $keptIds[] = (int) $item['media_id'];
            } else {
                $media = self::createMedia($item);

                if ($media === null) {
                    continue;
                }

                $record->media()->attach($media->id, [
                    'collection' => $collection,
                    'position' => $position,
                    'is_cover' => ! empty($item['is_cover']),
                ]);
                $keptIds[] = $media->id;
            }
        }

        self::removeOrphanedMedia($record, $collection, $existingIds, $keptIds);
    }

    private static function updateExistingMedia(
        Model&HasMedia $record,
        int $mediaId,
        string $collection,
        int $position,
        array $item,
    ): void {
        $updates = ['alt' => $item['alt'] ?? null];

        $type = MediaType::tryFrom($item['type'] ?? '') ?? MediaType::Upload;

        if ($type === MediaType::External || $type === MediaType::Embed) {
            $updates['url'] = $item['url'] ?? null;
        }

        Media::where('id', $mediaId)->update($updates);

        $record->media()->updateExistingPivot($mediaId, [
            'collection' => $collection,
            'position' => $position,
            'is_cover' => ! empty($item['is_cover']),
        ]);
    }

    private static function createMedia(array $item): ?Media
    {
        $type = MediaType::tryFrom($item['type'] ?? '') ?? MediaType::Upload;

        return match ($type) {
            MediaType::Upload => self::createUploadMedia($item),
            MediaType::External => self::createExternalMedia($item),
            MediaType::Embed => self::createEmbedMedia($item),
        };
    }

    private static function createUploadMedia(array $item): ?Media
    {
        $path = self::normalizeFileUploadPath($item['path'] ?? null);

        if ($path === null) {
            return null;
        }

        $disk = config('media.disk');
        $storage = Storage::disk($disk);

        return Media::create([
            'type' => MediaType::Upload,
            'disk' => $disk,
            'path' => $path,
            'filename' => basename($path),
            'mime_type' => $storage->mimeType($path) ?: 'application/octet-stream',
            'size' => $storage->size($path) ?: 0,
            'alt' => $item['alt'] ?? null,
        ]);
    }

    /**
     * Filament's FileUpload keeps its raw state as `array<string, string>` keyed
     * by an internal Livewire id, even for single-file uploads (see BaseFileUpload::saveUploadedFiles).
     * The dehydrated state collapses to a bare string, but `saveFormData` is invoked
     * from a Filament page hook against `$this->data`, which holds the raw shape.
     * Coerce both shapes here so the consumer doesn't have to.
     */
    private static function normalizeFileUploadPath(mixed $path): ?string
    {
        if (is_array($path)) {
            $path = Arr::first($path);
        }

        if (! is_string($path) || $path === '') {
            return null;
        }

        return $path;
    }

    private static function createExternalMedia(array $item): ?Media
    {
        if (empty($item['url'])) {
            return null;
        }

        $url = $item['url'];
        $filename = basename(parse_url($url, PHP_URL_PATH) ?: 'external');

        return Media::create([
            'type' => MediaType::External,
            'filename' => $filename,
            'mime_type' => 'image/jpeg',
            'size' => 0,
            'url' => $url,
            'alt' => $item['alt'] ?? null,
        ]);
    }

    private static function createEmbedMedia(array $item): ?Media
    {
        if (empty($item['url'])) {
            return null;
        }

        return Media::create([
            'type' => MediaType::Embed,
            'filename' => 'embed',
            'mime_type' => 'text/html',
            'size' => 0,
            'url' => $item['url'],
            'alt' => $item['alt'] ?? null,
        ]);
    }

    private static function embedPreview(string $url): HtmlString
    {
        $embedUrl = self::toEmbedUrl($url);

        if ($embedUrl === null) {
            return new HtmlString('<p style="color: #6b7280; font-size: 0.875rem;">Paste a YouTube or Vimeo URL above.</p>');
        }

        return new HtmlString(
            '<iframe src="'.e($embedUrl).'" style="width: 100%; aspect-ratio: 16/9; border-radius: 0.5rem; border: none;" allowfullscreen></iframe>',
        );
    }

    private static function toEmbedUrl(string $url): ?string
    {
        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1];
        }

        if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1];
        }

        return null;
    }

    private static function removeOrphanedMedia(
        Model&HasMedia $record,
        string $collection,
        array $existingIds,
        array $keptIds,
    ): void {
        $orphanedIds = array_diff($existingIds, $keptIds);

        if (empty($orphanedIds)) {
            return;
        }

        $record->media()->detach($orphanedIds);
        Media::whereIn('id', $orphanedIds)->get()->each->delete();
    }

    private static function validateCollection(string $collection): void
    {
        $valid = array_keys(self::collections());

        if (! in_array($collection, $valid, true)) {
            throw new InvalidArgumentException(
                "Invalid media collection '{$collection}'. Valid collections: ".implode(', ', $valid).'.',
            );
        }
    }

    private static function collectionLabel(string $collection): string
    {
        $config = self::collections()[$collection];

        return __($config['label'] ?? $collection);
    }
}
