<?php

declare(strict_types=1);

namespace InOtherShops\Media\Filament;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

        $schema = [
            Hidden::make('media_id'),
            Select::make('type')
                ->label(__('shops-common::fields.type'))
                ->options(self::collectionTypeOptions($collection))
                ->default(self::collectionTypes($collection)[0]->value)
                ->required()
                ->live()
                ->columnSpanFull(),
            FileUpload::make('path')
                ->label(__('shops-media::media.fields.path'))
                ->required()
                ->disk($disk)
                ->directory($directory)
                ->visibility('public')
                // Allowlist of mime types served safely from the public disk.
                // SVG is intentionally excluded — it executes JS when served inline.
                // Size cap is 10 MB; raise per-collection if a use case justifies it.
                ->acceptedFileTypes(self::allowedUploadMimeTypes())
                ->maxSize(10240)
                ->columnSpanFull()
                ->visible(fn ($get) => $get('type') === MediaType::Upload->value),
            TextInput::make('url')
                ->label(__('shops-media::media.fields.url'))
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
                ->label(__('shops-media::media.fields.alt'))
                ->maxLength(255),
            Textarea::make('description')
                ->label(__('shops-media::media.fields.description'))
                ->helperText(__('shops-media::media.fields.description_help'))
                ->rows(2)
                ->columnSpanFull(),
        ];

        // The cover toggle only makes sense for collections that hold actual
        // images. A collection of video embeds (or documents) carries URLs that
        // are never a valid <img> source, so flagging one as the cover yields a
        // broken image downstream. Opt a collection out with `cover => false`
        // in its `media.collections` config entry.
        if (self::collectionAllowsCover($collection)) {
            $schema[] = Toggle::make('is_cover')
                ->label(__('shops-media::media.fields.is_cover'))
                ->helperText(__('shops-media::media.fields.is_cover_help'))
                ->default(false);
        }

        return Repeater::make("_media.{$collection}")
            ->label($label)
            ->defaultItems(0)
            ->schema($schema)
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
                    'description' => $media->description,
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
            $allowsCover = self::collectionAllowsCover($collection);

            foreach ($items ?? [] as $index => $item) {
                $isCover = $allowsCover && ! empty($item['is_cover']);

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

    /**
     * Whether a collection's media can be flagged as the cover image. Defaults
     * to true; a collection holding non-image media (video embeds, documents)
     * opts out with `cover => false` in its `media.collections` config entry.
     */
    public static function collectionAllowsCover(string $collection): bool
    {
        return (self::collections()[$collection]['cover'] ?? true) !== false;
    }

    /**
     * Which media types a collection accepts. Defaults to all three; a
     * collection restricts itself with `types => ['embed']` in its
     * `media.collections` config entry.
     *
     * The reason this exists: a collection's type list is part of what the
     * collection *means*. A video-embed collection that still offers "Upload"
     * lets an editor put a JPEG where a player URL belongs — the upload
     * succeeds, and the breakage only shows up later as a dead embed on the
     * public page. Narrowing the options removes the wrong answer from the form
     * instead of validating it after the fact.
     *
     * An unknown or empty list falls back to all types rather than none, so a
     * config typo can't lock an editor out of their own media form.
     *
     * @return non-empty-list<MediaType>
     */
    public static function collectionTypes(string $collection): array
    {
        $configured = self::collections()[$collection]['types'] ?? null;

        if (! is_array($configured) || $configured === []) {
            return MediaType::cases();
        }

        $types = array_values(array_filter(array_map(
            static fn (mixed $value): ?MediaType => $value instanceof MediaType
                ? $value
                : (is_string($value) ? MediaType::tryFrom($value) : null),
            $configured,
        )));

        return $types === [] ? MediaType::cases() : $types;
    }

    /**
     * @return array<string, string>
     */
    private static function collectionTypeOptions(string $collection): array
    {
        $labels = [
            MediaType::Upload->value => __('shops-media::media.type_options.upload'),
            MediaType::External->value => __('shops-media::media.type_options.external'),
            MediaType::Embed->value => __('shops-media::media.type_options.embed'),
        ];

        $options = [];

        foreach (self::collectionTypes($collection) as $type) {
            $options[$type->value] = $labels[$type->value];
        }

        return $options;
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
        $updates = [
            'alt' => $item['alt'] ?? null,
            'description' => $item['description'] ?? null,
        ];

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
            'description' => $item['description'] ?? null,
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
            'description' => $item['description'] ?? null,
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
            'description' => $item['description'] ?? null,
        ]);
    }

    private static function embedPreview(string $url): HtmlString
    {
        $embedUrl = self::toEmbedUrl($url);

        if ($embedUrl === null) {
            return new HtmlString('<p style="color: #6b7280; font-size: 0.875rem;">'.e(__('shops-media::media.embed.prompt')).'</p>');
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

    /**
     * Allowlist of mime types accepted by the FileUpload field. Consumer projects
     * may override via `media.allowed_mime_types` in their config. SVG is omitted
     * deliberately; if a project needs it, they own the inline-XSS risk.
     *
     * @return array<int, string>
     */
    public static function allowedUploadMimeTypes(): array
    {
        $configured = config('media.allowed_mime_types');

        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter($configured, 'is_string'));
        }

        return [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/avif',
            'application/pdf',
        ];
    }
}
