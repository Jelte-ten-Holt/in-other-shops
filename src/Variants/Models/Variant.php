<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Models;

use InOtherShops\Inventory\Concerns\InteractsWithStock;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Media\Concerns\InteractsWithMedia;
use InOtherShops\Media\Contracts\HasMedia;
use InOtherShops\Pricing\Concerns\InteractsWithPrices;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Variants\Database\Factories\VariantFactory;
use InOtherShops\Variants\Variants;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A sellable SKU representing one combination of OptionValues, owned
 * polymorphically by a consumer model that implements `HasVariants`. Carries
 * its own price, stock, and media via the package's polymorphic capabilities.
 *
 * The package model is intentionally cart-agnostic in this phase; a consumer
 * swaps it (via `variants.models.variant`) for a subclass that adds `HasCart`
 * and its own purchasable role contract.
 */
class Variant extends Model implements HasMedia, HasPrices, HasStock
{
    use HasFactory;
    use InteractsWithMedia;
    use InteractsWithPrices;
    use InteractsWithStock;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new VariantFactory;
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function variantable(): MorphTo
    {
        return $this->morphTo();
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(Variants::optionValue(), 'option_value_variant');
    }

    /**
     * The variant's own descriptor — its option-value labels joined in option
     * order (e.g. "Silver, 45cm"). Consumers compose the full display name by
     * prefixing the owner's name. Pass `$locale` to render in a specific locale
     * (e.g. for an order-line snapshot); defaults to the active locale.
     */
    public function optionSummary(?string $locale = null): string
    {
        return $this->optionValues
            ->sortBy(fn (OptionValue $value): int => $value->option->position)
            ->map(fn (OptionValue $value): string => $value->translated('label', $locale) ?? $value->value)
            ->implode(', ');
    }
}
