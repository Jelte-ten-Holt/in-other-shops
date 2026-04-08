<?php

declare(strict_types=1);

namespace InOtherShops\Translation\Concerns;

use InOtherShops\Translation\Translation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait InteractsWithTranslations
{
    public function translations(): MorphMany
    {
        $model = Translation::translation();

        return $this->morphMany($model::class, 'translatable');
    }

    public function translated(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        $translation = $this->findTranslation($field, $locale);

        if ($translation !== null) {
            return $translation;
        }

        return $this->findFallbackTranslation($field, $locale);
    }

    /** @return array<string, string> */
    public function translationsFor(string $field): array
    {
        return $this->translations
            ->where('field', $field)
            ->pluck('value', 'locale')
            ->all();
    }

    public function setTranslation(string $field, string $locale, string $value): void
    {
        $this->translations()->updateOrCreate(
            ['locale' => $locale, 'field' => $field],
            ['value' => $value],
        );

        $this->unsetRelation('translations');
    }

    /** @param array<string, string> $translations */
    public function setTranslations(string $locale, array $translations): void
    {
        foreach ($translations as $field => $value) {
            if (! in_array($field, $this->translatableFields(), true)) {
                continue;
            }

            $this->translations()->updateOrCreate(
                ['locale' => $locale, 'field' => $field],
                ['value' => $value],
            );
        }

        $this->unsetRelation('translations');
    }

    public function getAttribute($key): mixed
    {
        if ($this->isTranslatableField($key)) {
            return $this->translated($key);
        }

        return parent::getAttribute($key);
    }

    public function scopeWithTranslations(Builder $query, ?string $locale = null): Builder
    {
        if ($locale !== null) {
            return $query->with(['translations' => fn (MorphMany $q) => $q->where('locale', $locale)]);
        }

        return $query->with('translations');
    }

    public function scopeWhereTranslation(Builder $query, string $field, string $operator, string $value, ?string $locale = null): Builder
    {
        $locale ??= app()->getLocale();

        return $query->whereHas('translations', function (Builder $q) use ($field, $operator, $value, $locale) {
            $q->where('field', $field)
                ->where('locale', $locale)
                ->where('value', $operator, $value);
        });
    }

    public function scopeOrderByTranslation(Builder $query, string $field, string $direction = 'asc', ?string $locale = null): Builder
    {
        $locale ??= app()->getLocale();

        return $query
            ->leftJoin('translations', function ($join) use ($field, $locale) {
                $join->on('translations.translatable_id', '=', $this->getTable().'.'.$this->getKeyName())
                    ->where('translations.translatable_type', $this->getMorphClass())
                    ->where('translations.field', $field)
                    ->where('translations.locale', $locale);
            })
            ->orderBy('translations.value', $direction)
            ->select($this->getTable().'.*');
    }

    private function findTranslation(string $field, string $locale): ?string
    {
        return $this->translations
            ->where('field', $field)
            ->where('locale', $locale)
            ->first()
            ?->value;
    }

    private function findFallbackTranslation(string $field, string $locale): ?string
    {
        $fallback = config('translation.fallback');

        if ($fallback === null || $fallback === $locale) {
            return null;
        }

        return $this->findTranslation($field, $fallback);
    }

    private function isTranslatableField(string $key): bool
    {
        if (! method_exists($this, 'translatableFields')) {
            return false;
        }

        return in_array($key, $this->translatableFields(), true);
    }
}
