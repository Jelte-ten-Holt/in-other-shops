<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Models;

use InOtherShops\Media\Concerns\InteractsWithMedia;
use InOtherShops\Media\Contracts\HasMedia;
use InOtherShops\Media\Models\Media;
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
 * (unique with `option_id`). May carry a single swatch image (HasMedia, in the
 * `swatch` collection) for a visual storefront variant picker.
 */
class OptionValue extends Model implements HasMedia, HasTranslations
{
    use HasFactory;
    use InteractsWithMedia;
    use InteractsWithTranslations;

    /** Media collection holding the (single) swatch image for this value. */
    public const string SWATCH_COLLECTION = 'swatch';

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

    /** The value's swatch image, if one has been set. */
    public function swatch(): ?Media
    {
        return $this->firstMedia(self::SWATCH_COLLECTION);
    }
}
