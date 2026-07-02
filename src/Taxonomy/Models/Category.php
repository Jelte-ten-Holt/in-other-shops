<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Models;

use InOtherShops\Media\Concerns\InteractsWithMedia;
use InOtherShops\Media\Contracts\HasMedia;
use InOtherShops\Taxonomy\Concerns\InteractsWithTags;
use InOtherShops\Taxonomy\Contracts\HasTags;
use InOtherShops\Taxonomy\Database\Factories\CategoryFactory;
use InOtherShops\Taxonomy\Taxonomy;
use InOtherShops\Translation\Concerns\InteractsWithTranslations;
use InOtherShops\Translation\Contracts\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Category extends Model implements HasMedia, HasTags, HasTranslations
{
    use HasFactory;
    use InteractsWithMedia;
    use InteractsWithTags;
    use InteractsWithTranslations;

    protected $guarded = [];

    protected static string $factory = CategoryFactory::class;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * A category move (parent_id change) and a delete both fire CategoryObserver
     * hooks that synchronously maintain category_morph_counts along ancestor
     * chains. The triggering write and that reaction must commit atomically —
     * otherwise a failure mid-walk leaves the row moved/deleted with ancestor
     * counts only half-shifted, and the exception does not roll the write back.
     * Wrapping save()/delete() in a transaction encloses the Eloquent
     * updated/deleting events (which fire inside these calls) with the write.
     * Nested transactions resolve to savepoints, so a caller-level transaction
     * is unaffected. See audit finding B-2.
     */
    public function save(array $options = [])
    {
        return DB::transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        return DB::transaction(fn () => parent::delete());
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
