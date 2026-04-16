<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Models;

use InOtherShops\Taxonomy\Taxonomy;
use InOtherShops\Translation\Concerns\InteractsWithTranslations;
use InOtherShops\Translation\Contracts\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model implements HasTranslations
{
    use InteractsWithTranslations;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return array<string> */
    public function translatableFields(): array
    {
        return ['name', 'description'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Taxonomy::category(), 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Taxonomy::category(), 'parent_id');
    }
}
