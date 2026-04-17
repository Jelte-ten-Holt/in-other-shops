<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Models;

use InOtherShops\Taxonomy\Database\Factories\CategoryFactory;
use InOtherShops\Taxonomy\Taxonomy;
use InOtherShops\Translation\Concerns\InteractsWithTranslations;
use InOtherShops\Translation\Contracts\HasTranslations;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model implements HasTranslations
{
    use HasFactory;
    use InteractsWithTranslations;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new CategoryFactory;
    }

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
