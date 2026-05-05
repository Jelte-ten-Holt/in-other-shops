<?php

declare(strict_types=1);

namespace InOtherShops\Translation\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

interface HasLocaleGroup
{
    public function localeGroup(): BelongsTo;

    /**
     * Query builder for sibling rows of the same model class in the same locale group, excluding self.
     */
    public function siblings(): Builder;

    /**
     * Resolve the sibling for the given locale, or return self if it matches.
     * Returns null when no row exists for the requested locale.
     */
    public function inLocale(string $locale): ?self;

    /**
     * The row's effective locale. Falls back to the configured app locale when the column is null
     * (only valid during backfill — application logic should always populate the column).
     */
    public function locale(): string;
}
