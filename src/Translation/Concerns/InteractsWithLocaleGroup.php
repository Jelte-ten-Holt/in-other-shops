<?php

declare(strict_types=1);

namespace InOtherShops\Translation\Concerns;

use InOtherShops\Translation\Translation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait InteractsWithLocaleGroup
{
    public function localeGroup(): BelongsTo
    {
        return $this->belongsTo(Translation::localeGroup(), 'locale_group_id');
    }

    public function siblings(): Builder
    {
        $query = static::query()->whereKeyNot($this->getKey());

        if ($this->locale_group_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('locale_group_id', $this->locale_group_id);
    }

    public function inLocale(string $locale): ?self
    {
        if ($this->locale === $locale) {
            return $this;
        }

        if ($this->locale_group_id === null) {
            return null;
        }

        /** @var ?static */
        return static::query()
            ->where('locale_group_id', $this->locale_group_id)
            ->where('locale', $locale)
            ->first();
    }

    public function locale(): string
    {
        return $this->locale ?? config('app.locale');
    }

    public function scopeForLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    public function scopeMonolingual(Builder $query): Builder
    {
        return $query->whereNull('locale_group_id');
    }
}
