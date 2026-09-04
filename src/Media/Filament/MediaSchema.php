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
            ...self::translatableTextInputs(),
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
     * The alt and description inputs, one pair per configured locale.
     *
     * Both fields are read by a person, so on a multi-locale storefront they
     * belong in the `translations` table rather than in a column — one photo
     * hangs on a record shared across language editions, and a column could
     * only ever hold one language's words. A single-locale consumer sees
     * exactly one of each input, unchanged from when they were columns.
     *
     * The locale suffix is only shown when there is more than one locale;
     * labelling a lone field "Alt (EN)" is noise in a shop that has no other
     * language to distinguish it from.
     *
     * @return list<TextInput|Textarea>
     */
    private static function translatableTextInputs(): array
    {
        $locales = self::locales();
        $multi = count($locales) > 1;

        $fields = [];

        foreach ($locales as $locale) {
            $suffix = $multi ? ' ('.strtoupper($locale).')' : '';

            $fields[] = TextInput::make("alt.{$locale}")
                ->label(__('shops-media::media.fields.alt').$suffix)
                ->maxLength(255);

            $fields[] = Textarea::make("description.{$locale}")
                ->label(__('shops-media::media.fields.description').$suffix)
                ->helperText(__('shops-media::media.fields.description_help'))
                ->rows(2)
                ->columnSpanFull();
        }

        return $fields;
    }

    /** @return non-empty-list<string> */
    private static function locales(): array
    {
        $configured = config('translation.locales', ['en']);

        if (! is_array($configured) || $configured === []) {
            return ['en'];
        }

        return array_values(array_filter($configured, 'is_string')) ?: ['en'];
    }

    /**
     * Per-locale values for one media row, as the repeater state expects them:
     * `['alt' => ['es' => …, 'en' => …], 'description' => [...]]`. Reads the
     * loaded `translations` relation rather than the accessor, because the
     * accessor answers for the *current* locale with a fallback and the form
     * needs each locale's own value, blanks included.
     *
     * @return array<string, array<string, string>>
     */
    private static function translatableState(Media $media): array
    {
        $state = [];

        foreach (['alt', 'description'] as $field) {
            foreach (self::locales() as $locale) {
                $state[$field][$locale] = $media->translations
                    ->where('locale', $locale)
                    ->where('field', $field)
                    ->first()
                    ?->value ?? '';
            }
        }

        return $state;
    }

    /**
     * Write one media row's per-locale alt and description. A blank clears that
     * locale's row rather than storing an empty string — "no caption" and "a
     * caption that is the empty string" are the same thing to a reader, and
     * only one of them survives a round trip.
     *
     * @param  array<string, mixed>  $item
     */
    private static function syncTranslatableText(Media $media, array $item): void
    {
        foreach (['alt', 'description'] as $field) {
            $values = $item[$field] ?? [];

            if (! is_array($values)) {
                continue;
            }

            foreach (self::locales() as $locale) {
                $value = $values[$locale] ?? null;

                if ($value === null || $value === '') {
                    $media->translations()
                        ->where('locale', $locale)
                        ->where('field', $field)
                        ->delete();

                    continue;
                }

                $media->setTranslation($field, $locale, (string) $value);
            }
        }

        $media->unsetRelation('translations');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillFormData(Model&HasMedia $record, array $data): array
    {
        $record->load('media.translations');

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
                    ...self::translatableState($media),
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
            $keptId = ! empty($item['media_id'])
                ? self::updateExistingMedia($record, (int) $item['media_id'], $collection, $position, $item)
                : self::createAndAttachMedia($record, $collection, $position, $item);

            if ($keptId !== null) {
                $keptIds[] = $keptId;
            }
        }

        self::removeOrphanedMedia($record, $collection, $existingIds, $keptIds);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return int|null the media id that now occupies this row, or null if none
     */
    private static function updateExistingMedia(
        Model&HasMedia $record,
        int $mediaId,
        string $collection,
        int $position,
        array $item,
    ): ?int {
        $media = Media::find($mediaId);

        if ($media === null) {
            return null;
        }

        $type = MediaType::tryFrom($item['type'] ?? '') ?? MediaType::Upload;

        // A changed type is not an update. An upload row and an external row
        // share no state worth carrying across — keeping the row would leave
        // `type=upload` next to a `url`, and `url()` would go on serving the
        // old file. Delete and recreate at the same position and cover flag;
        // `Media::deleting` removes the upload's file as it goes.
        if ($type !== $media->type) {
            $record->media()->detach($mediaId);
            $media->delete();

            return self::createAndAttachMedia($record, $collection, $position, $item);
        }

        if ($type === MediaType::External || $type === MediaType::Embed) {
            $media->forceFill(['url' => $item['url'] ?? null])->save();
        }

        // Swapping the file on an existing repeater row: `media_id` survives in
        // its Hidden field, so this lands here rather than in `createMedia`,
        // and until v0.68.0 `path` was simply never read — the new file was
        // uploaded and then referenced by nothing while the site kept serving
        // the old one. Writing `path` is all that is needed; the model refreshes
        // the metadata and removes the replaced file (`Media::refreshFileMetadata`,
        // `Media::deleteReplacedFile`).
        if ($type === MediaType::Upload) {
            $path = self::normalizeFileUploadPath($item['path'] ?? null);

            if ($path !== null && $path !== $media->path) {
                $media->forceFill(['path' => $path])->save();
            }
        }

        self::syncTranslatableText($media, $item);

        $record->media()->updateExistingPivot($mediaId, [
            'collection' => $collection,
            'position' => $position,
            'is_cover' => ! empty($item['is_cover']),
        ]);

        return $mediaId;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return int|null the new media id, or null when the item carries nothing storable
     */
    private static function createAndAttachMedia(
        Model&HasMedia $record,
        string $collection,
        int $position,
        array $item,
    ): ?int {
        $media = self::createMedia($item);

        if ($media === null) {
            return null;
        }

        $record->media()->attach($media->id, [
            'collection' => $collection,
            'position' => $position,
            'is_cover' => ! empty($item['is_cover']),
        ]);

        return $media->id;
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

        $media = Media::create([
            'type' => MediaType::Upload,
            'disk' => $disk,
            'path' => $path,
            'filename' => basename($path),
            'mime_type' => $storage->mimeType($path) ?: 'application/octet-stream',
            'size' => $storage->size($path) ?: 0,
        ]);

        self::syncTranslatableText($media, $item);

        return $media;
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

        $media = Media::create([
            'type' => MediaType::External,
            'filename' => $filename,
            'mime_type' => 'image/jpeg',
            'size' => 0,
            'url' => $url,
        ]);

        self::syncTranslatableText($media, $item);

        return $media;
    }

    private static function createEmbedMedia(array $item): ?Media
    {
        if (empty($item['url'])) {
            return null;
        }

        $media = Media::create([
            'type' => MediaType::Embed,
            'filename' => 'embed',
            'mime_type' => 'text/html',
            'size' => 0,
            'url' => $item['url'],
        ]);

        self::syncTranslatableText($media, $item);

        return $media;
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
