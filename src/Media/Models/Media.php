<?php

declare(strict_types=1);

namespace InOtherShops\Media\Models;

use InOtherShops\Media\Database\Factories\MediaFactory;
use InOtherShops\Media\Enums\MediaType;
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

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'type' => MediaType::class,
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (Media $media): void {
            $media->refreshFileMetadata();
        });

        self::deleting(function (Media $media): void {
            if ($media->type === MediaType::Upload && $media->disk && $media->path && ! $media->fileIsShared()) {
                Storage::disk($media->disk)->delete($media->path);
            }

            $media->translations()->delete();
        });

        self::saved(function (Media $media): void {
            $media->flushPendingTranslations();
            $media->deleteReplacedFile();
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
     * The replace invariant, second half: once the row points somewhere else,
     * nothing references the old file and Filament never removes it. Drop it —
     * unless another row still points at it, the same guard `deleting` uses.
     *
     * After commit, because bianka's panel runs `->databaseTransactions()`: a
     * rollback there would otherwise restore the row and leave the file it
     * points at deleted. Outside a transaction `DB::afterCommit()` runs the
     * callback immediately, so this is right on both consumers.
     */
    public function deleteReplacedFile(): void
    {
        if ($this->type !== MediaType::Upload || ! $this->wasChanged('path')) {
            return;
        }

        $originalPath = $this->getOriginal('path');
        $originalDisk = $this->getOriginal('disk') ?? $this->disk;

        if (! is_string($originalPath) || $originalPath === '' || $originalPath === $this->path) {
            return;
        }

        if (! is_string($originalDisk) || $originalDisk === '') {
            return;
        }

        DB::afterCommit(function () use ($originalPath, $originalDisk): void {
            if ($this->fileIsShared($originalPath, $originalDisk)) {
                return;
            }

            Storage::disk($originalDisk)->delete($originalPath);
        });
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
