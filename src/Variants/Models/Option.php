<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Models;

use InOtherShops\Translation\Concerns\InteractsWithTranslations;
use InOtherShops\Translation\Contracts\HasTranslations;
use InOtherShops\Variants\Database\Factories\OptionFactory;
use InOtherShops\Variants\Variants;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A variant axis (Metal, Ring Size). Global catalog — defined once and attached
 * to many owners via the `optionables` pivot. Owns an ordered set of
 * OptionValues. The display `name` is translatable (column-translation); `slug`
 * is the stable, non-translated identifier.
 */
class Option extends Model implements HasTranslations
{
    use HasFactory;
    use InteractsWithTranslations;

    protected $guarded = [];

    protected static string $factory = OptionFactory::class;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /** @return array<string> */
    public function translatableFields(): array
    {
        return ['name'];
    }

    public function values(): HasMany
    {
        return $this->hasMany(Variants::optionValue(), 'option_id')->orderBy('position');
    }
}
