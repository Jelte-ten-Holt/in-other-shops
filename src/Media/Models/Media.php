<?php

declare(strict_types=1);

namespace InOtherShops\Media\Models;

use InOtherShops\Media\Database\Factories\MediaFactory;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Jobs\GenerateImageVariants;
use InOtherShops\Media\Support\ImageOrientation;
use InOtherShops\Translation\Concerns\InteractsWithTranslations;
use InOtherShops\Translation\Contracts\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Media extends Model implements HasTranslations
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;
    use InteractsWithTranslations;

    protected $table = 'media';

    protected static string $factory = MediaFactory::class;

    protected $guarded = [];

    /**
     * `alt` and `description` are not columns — they live in the `translations`
     * table, one row per locale. Appending them keeps `toArray()` (and every
     * Inertia payload built from a Media model) shaped exactly as it was when
     * they were columns.
     *
     * @var list<string>
     */
    protected $appends = ['alt', 'description'];

    /**
     * Resolving either field reads the `translations` relation, so it is always
     * loaded. This is one extra query per media *batch*, not per row — the
     * alternative is a lazy load per image on every gallery and card.
     *
     * @var list<string>
     */
    protected $with = ['translations'];

    /**
     * Translatable values assigned but not yet persisted, keyed by field.
     *
     * A translation row needs the media's id, which does not exist until the
     * insert completes. Buffering lets `Media::create(['alt' => 'Hero'])` keep
     * working — the assignment lands in the default locale on `saved`.
     *
     * @var array<string, string|null>
     */
    private array $pendingTranslations = [];

    /**
     * The path this instance last queued a variant job for. `wasRecentlyCreated`
     * stays true for the instance's whole life, so without this a second save
     * on a just-created row (a translation, a pivot touch) would queue the job
     * again. The unique lock would absorb it; better not to ask.
     */
    private ?string $variantsDispatchedFor = null;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'type' => MediaType::class,
            'width' => 'integer',
            'height' => 'integer',
            'variants' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (Media $media): void {
            $media->refreshFileMetadata();
            $media->refreshDimensions();
        });

        self::deleting(function (Media $media): void {
            $media->deleteOwnFiles();
            $media->translations()->delete();
        });

        self::saved(function (Media $media): void {
            $media->flushPendingTranslations();
            $media->deleteReplacedFile();
            $media->dispatchVariantGeneration();
        });
    }

    /**
     * The replace invariant, first half: a `path` that changes on an existing
     * upload row means a *different file*, so the metadata describing the old
     * one is now wrong. Refresh it from the disk rather than trusting whatever
     * the caller happened to pass.
     *
     * This lives on the model, not in the admin form, because there are two
     * upload surfaces (`MediaSchema`'s repeater and `MediaRelationManager`'s
     * Edit action) and each had its own half of the bug: the repeater never
     * wrote `path` at all, the relation manager wrote `path` but left
     * `filename`/`mime_type`/`size` describing the replaced file. Anything that
     * writes `path` gets the invariant, including a future third surface.
     *
     * Deliberately updates only — on an insert the creator owns the metadata
     * (`StoreMedia` stores the *client's* original filename, which the path's
     * ULID basename would otherwise clobber). A replacement has no client
     * filename to recover, so it takes the stored basename.
     */
    public function refreshFileMetadata(): void
    {
        if (! $this->exists || $this->type !== MediaType::Upload || ! $this->isDirty('path')) {
            return;
        }

        if (! is_string($this->disk) || $this->disk === '' || ! is_string($this->path) || $this->path === '') {
            return;
        }

        $storage = Storage::disk($this->disk);

        if (! $storage->exists($this->path)) {
            return;
        }

        $this->filename = basename($this->path);
        $this->mime_type = $storage->mimeType($this->path) ?: 'application/octet-stream';
        $this->size = $storage->size($this->path) ?: 0;
    }

    /**
     * Intrinsic dimensions, filled whenever `path` is dirty on an upload row —
     * on create as well as on replace (D3). `getimagesize()` reads a header,
     * not pixels: no decode, no memory shape, so it belongs in the request.
     * EXIF orientations 5–8 transpose the pair (F9): a portrait phone photo
     * stores landscape pixels and a rotation flag, and the browser shows it
     * upright, so the row must describe what the browser shows.
     *
     * A path change also resets `variants`: the rungs described the old file.
     * The job re-fills it after commit.
     */
    public function refreshDimensions(): void
    {
        if ($this->type !== MediaType::Upload || ! $this->isDirty('path')) {
            return;
        }

        if ($this->exists) {
            $this->variants = null;
        }

        $this->readDimensions();
    }

    /**
     * Read `width`/`height` from the file on disk into the attributes (unsaved).
     * Nulls them when there is nothing to read: not an image, not a local disk,
     * file missing, header unreadable. Local only because `getimagesize()`
     * needs a filesystem path; a remote disk would mean a download per save.
     */
    public function readDimensions(): void
    {
        $this->width = null;
        $this->height = null;

        if (! is_string($this->disk) || $this->disk === '' || ! is_string($this->path) || $this->path === '' || ! $this->isImage()) {
            return;
        }

        if (config("filesystems.disks.{$this->disk}.driver") !== 'local') {
            return;
        }

        $storage = Storage::disk($this->disk);

        if (! $storage->exists($this->path)) {
            return;
        }

        $file = $storage->path($this->path);
        $info = @getimagesize($file);

        if ($info === false) {
            return;
        }

        [$width, $height] = $info;

        if (ImageOrientation::transposes(ImageOrientation::read($file, $info['mime'] ?? $this->mime_type))) {
            [$width, $height] = [$height, $width];
        }

        $this->width = $width;
        $this->height = $height;
    }

    /**
     * Queue the variant ladder for a new file — on create, or when `path`
     * changed. That guard is the recursion breaker: the job's own write-back
     * touches `variants` only (and saves quietly besides). After commit so a
     * transactional panel cannot hand the worker a row that is not there yet.
     *
     * `type=Upload` is checked explicitly and not only `isImage()`: external
     * rows are created with an image mime type and no file.
     */
    public function dispatchVariantGeneration(): void
    {
        if (! config('media.variants.enabled', true)) {
            return;
        }

        if ($this->type !== MediaType::Upload || ! $this->isImage()) {
            return;
        }

        if (! $this->wasRecentlyCreated && ! $this->wasChanged('path')) {
            return;
        }

        if (! is_string($this->disk) || $this->disk === '' || ! is_string($this->path) || $this->path === '') {
            return;
        }

        if ($this->variantsDispatchedFor === $this->path) {
            return;
        }

        $this->variantsDispatchedFor = $this->path;

        GenerateImageVariants::dispatch((int) $this->getKey(), $this->disk, $this->path)->afterCommit();
    }

    /**
     * `deleting`: the file and its rungs go together, unless another row
     * shares the file — the rungs are keyed off the original's path, so the
     * same guard covers both.
     */
    public function deleteOwnFiles(): void
    {
        if ($this->type !== MediaType::Upload || ! $this->disk || ! $this->path || $this->fileIsShared()) {
            return;
        }

        Storage::disk($this->disk)->delete([$this->path, ...$this->variantFilePaths()]);
    }

    /**
     * The replace invariant, second half: once the row points somewhere else,
     * nothing references the old file and Filament never removes it. Drop it,
     * and its rungs — unless another row still points at it, the same guard
     * `deleting` uses.
     *
     * After commit, because bianka's panel runs `->databaseTransactions()`: a
     * rollback there would otherwise restore the row and leave the file it
     * points at deleted. Outside a transaction `DB::afterCommit()` runs the
     * callback immediately, so this is right on both consumers.
     *
     * The rung names come from the OLD path plus the old map, captured here
     * rather than read from the column — `saving` has already reset it.
     */
    public function deleteReplacedFile(): void
    {
        if ($this->type !== MediaType::Upload || ! $this->wasChanged('path')) {
            return;
        }

        $originalPath = $this->getOriginal('path');
        $originalDisk = $this->getOriginal('disk') ?? $this->disk;
        $originalVariants = $this->getOriginal('variants');

        if (! is_string($originalPath) || $originalPath === '' || $originalPath === $this->path) {
            return;
        }

        if (! is_string($originalDisk) || $originalDisk === '') {
            return;
        }

        $files = [$originalPath, ...$this->variantFilePaths($originalPath, is_array($originalVariants) ? $originalVariants : null)];

        DB::afterCommit(function () use ($files, $originalPath, $originalDisk): void {
            if ($this->fileIsShared($originalPath, $originalDisk)) {
                return;
            }

            Storage::disk($originalDisk)->delete($files);
        });
    }

    /**
     * Where the rung for `$width` lives: beside the original, as
     * `{stem}-w{width}.webp`. ULID stems make this collision-free.
     */
    public function variantPath(int $width): string
    {
        return self::variantPathFor((string) $this->path, $width);
    }

    public static function variantPathFor(string $path, int $width): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $stem = pathinfo($path, PATHINFO_FILENAME);
        $prefix = $directory === '.' || $directory === '' ? '' : rtrim($directory, '/').'/';

        return "{$prefix}{$stem}-w{$width}.webp";
    }

    /**
     * Every rung file that could belong to `$path`: what the map records PLUS
     * what the current ladder would name. The union, because the ladder may
     * have changed since the map was written and a stale rung is still ours
     * to remove.
     *
     * @param  array<int|string, mixed>|null  $variants
     * @return list<string>
     */
    public function variantFilePaths(?string $path = null, ?array $variants = null): array
    {
        $path ??= $this->path;

        if (! is_string($path) || $path === '') {
            return [];
        }

        $paths = [];

        foreach ($variants ?? (is_array($this->variants) ? $this->variants : []) as $variant) {
            if (is_array($variant) && is_string($variant['path'] ?? null) && $variant['path'] !== '') {
                $paths[] = $variant['path'];
            }
        }

        foreach (GenerateImageVariants::ladder() as $width) {
            $paths[] = self::variantPathFor($path, $width);
        }

        return array_values(array_unique($paths));
    }

    /**
     * The srcset candidates (D8/D9): each rung, then the original as the
     * widest — when its width is known — ascending. `null` while there are
     * no rungs, so a consumer renders a plain `<img>` and nothing else.
     *
     * @return list<array{url: string, width: int}>|null
     */
    public function srcset(): ?array
    {
        if ($this->type !== MediaType::Upload || ! is_array($this->variants) || $this->variants === []) {
            return null;
        }

        if (! is_string($this->disk) || $this->disk === '') {
            return null;
        }

        $storage = Storage::disk($this->disk);
        $candidates = [];

        foreach ($this->variants as $variant) {
            if (! is_array($variant) || ! is_string($variant['path'] ?? null) || ! isset($variant['width'])) {
                continue;
            }

            $candidates[] = ['url' => $storage->url($variant['path']), 'width' => (int) $variant['width']];
        }

        if ($this->width !== null) {
            $candidates[] = ['url' => $this->url(), 'width' => (int) $this->width];
        }

        usort($candidates, fn (array $a, array $b): int => $a['width'] <=> $b['width']);

        return $candidates === [] ? null : $candidates;
    }

    /**
     * Whether another media row points at this row's file.
     *
     * `MediaSchema::saveFormData` re-creates a row it cannot match (a form
     * state without `media_id` — an Edit page that did not refill after its
     * last save) before it removes the orphan, so at deletion time the file is
     * already referenced by the replacement. Deleting it then leaves the live
     * row pointing at nothing, silently. The row goes; the file stays as long
     * as anything else needs it.
     */
    public function fileIsShared(?string $path = null, ?string $disk = null): bool
    {
        return static::query()
            ->whereKeyNot($this->getKey())
            ->where('disk', $disk ?? $this->disk)
            ->where('path', $path ?? $this->path)
            ->exists();
    }

    /** @return list<string> */
    public function translatableFields(): array
    {
        return ['alt', 'description'];
    }

    /**
     * Accessors (rather than only the trait's `getAttribute` hook) so `$appends`
     * can reach these fields — `attributesToArray()` resolves an appended key
     * through a `get{Field}Attribute` mutator and nothing else.
     */
    public function getAltAttribute(): ?string
    {
        return $this->resolveTranslatable('alt');
    }

    public function getDescriptionAttribute(): ?string
    {
        return $this->resolveTranslatable('description');
    }

    public function getAttribute($key): mixed
    {
        if (in_array($key, $this->translatableFields(), true)) {
            return $this->resolveTranslatable($key);
        }

        return parent::getAttribute($key);
    }

    public function setAttribute($key, $value): mixed
    {
        if (in_array($key, $this->translatableFields(), true)) {
            $this->pendingTranslations[$key] = $value === null ? null : (string) $value;

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Write buffered assignments into the default locale, then forget them.
     * An empty or null assignment removes the row rather than storing a blank —
     * "no alt text" and "alt text that is the empty string" are the same thing,
     * and only one of them survives a round trip.
     */
    public function flushPendingTranslations(): void
    {
        if ($this->pendingTranslations === []) {
            return;
        }

        $locale = config('translation.default', 'en');
        $pending = $this->pendingTranslations;
        $this->pendingTranslations = [];

        foreach ($pending as $field => $value) {
            if ($value === null || $value === '') {
                $this->translations()
                    ->where('locale', $locale)
                    ->where('field', $field)
                    ->delete();

                continue;
            }

            $this->translations()->updateOrCreate(
                ['locale' => $locale, 'field' => $field],
                ['value' => $value],
            );
        }

        $this->unsetRelation('translations');
    }

    private function resolveTranslatable(string $field): ?string
    {
        if (array_key_exists($field, $this->pendingTranslations)) {
            return $this->pendingTranslations[$field];
        }

        return $this->translated($field);
    }

    public function url(): string
    {
        return match ($this->type) {
            MediaType::Upload => Storage::disk($this->disk)->url($this->path),
            MediaType::External, MediaType::Embed => $this->getAttribute('url'),
        };
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
