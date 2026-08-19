<?php

declare(strict_types=1);

namespace InOtherShops\Media\Models;

use InOtherShops\Media\Database\Factories\MediaFactory;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Translation\Concerns\InteractsWithTranslations;
use InOtherShops\Translation\Contracts\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        self::deleting(function (Media $media): void {
            if ($media->type === MediaType::Upload && $media->disk && $media->path) {
                Storage::disk($media->disk)->delete($media->path);
            }

            $media->translations()->delete();
        });

        self::saved(function (Media $media): void {
            $media->flushPendingTranslations();
        });
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
