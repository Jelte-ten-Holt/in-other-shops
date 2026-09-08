<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Models;

use InOtherShops\Taxonomy\Database\Factories\TagFactory;
use InOtherShops\Translation\Concerns\InteractsWithTranslations;
use InOtherShops\Translation\Contracts\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model implements HasTranslations
{
    use HasFactory;
    use InteractsWithTranslations;

    protected $guarded = [];

    /**
     * Every read of a translated field goes through the `translations`
     * relation, and every surface that lists these rows (the admin tables,
     * `ListCategoryTree`, a consumer's filter UI) reads a name per row. One
     * query per batch beats a lazy load per row. Deliberately unconstrained by
     * locale: `findFallbackTranslation` searches this same collection, so a
     * locale filter here would hide the fallback row and resolve a name-less
     * item to null.
     *
     * @var list<string>
     */
    protected $with = ['translations'];

    protected static string $factory = TagFactory::class;

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
        return ['name'];
    }
}
