<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Models;

use InOtherShops\Commerce\Cart\Concerns\InteractsWithCart;
use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Currency\Enums\Currency;
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
 * its own price, stock, and media via the package's polymorphic capabilities,
 * and is the cart-able unit when an owner has variants.
 *
 * A consumer may swap it (via `variants.models.variant`) for a subclass that
 * adds its own purchasable role contract on top of these package mechanics.
 */
class Variant extends Model implements HasCart, HasMedia, HasPrices, HasStock
{
    use HasFactory;
    use InteractsWithCart;
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

    /**
     * Cart-line label: the owner's label plus this variant's option summary
     * ("Pendant — Silver, 45cm"). Falls back to the summary alone when the
     * owner doesn't expose a cart-able label.
     */
    public function getCartableLabel(): string
    {
        $summary = $this->optionSummary();
        $owner = $this->variantable;

        if ($owner instanceof HasCart) {
            return $summary === ''
                ? $owner->getCartableLabel()
                : $owner->getCartableLabel().' — '.$summary;
        }

        return $summary;
    }

    public function getCartableDescription(): ?string
    {
        $owner = $this->variantable;

        return $owner instanceof HasCart ? $owner->getCartableDescription() : null;
    }

    public function getCartableUnitPrice(Currency $currency): ?int
    {
        return $this->priceFor($currency)?->amount;
    }
}
