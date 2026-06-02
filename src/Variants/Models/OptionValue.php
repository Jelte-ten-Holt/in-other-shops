<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Models;

use InOtherShops\Translation\Concerns\InteractsWithTranslations;
use InOtherShops\Translation\Contracts\HasTranslations;
use InOtherShops\Variants\Database\Factories\OptionValueFactory;
use InOtherShops\Variants\Variants;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A value of an Option (Silver, Size 7). Belongs to one Option, ordered by
 * `position` within it. The display `label` is translatable
 * (column-translation); `value` is the stable, per-Option identifier
 * (unique with `option_id`).
 */
class OptionValue extends Model implements HasTranslations
{
    use HasFactory;
    use InteractsWithTranslations;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new OptionValueFactory;
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /** @return array<string> */
    public function translatableFields(): array
    {
        return ['label'];
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(Variants::option(), 'option_id');
    }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(Variants::variant(), 'option_value_variant');
    }
}
