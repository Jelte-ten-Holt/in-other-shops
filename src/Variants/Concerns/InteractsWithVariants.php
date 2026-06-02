<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Concerns;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Variants\Models\Variant;
use InOtherShops\Variants\Variants;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait InteractsWithVariants
{
    public function variants(): MorphMany
    {
        return $this->morphMany(Variants::variant(), 'variantable')->orderBy('position');
    }

    public function options(): MorphToMany
    {
        return $this->morphToMany(Variants::option(), 'optionable')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function hasVariants(): bool
    {
        if ($this->relationLoaded('variants')) {
            return $this->variants->isNotEmpty();
        }

        return $this->variants()->exists();
    }

    /**
     * The "from" price — the lowest resolved unit price across this owner's
     * variants in the given currency. Null when no variant has a price.
     */
    public function lowestVariantPrice(Currency $currency): ?int
    {
        $amounts = $this->variants
            ->map(fn (Variant $variant): ?int => $variant->priceFor($currency)?->amount)
            ->filter(fn (?int $amount): bool => $amount !== null);

        return $amounts->isEmpty() ? null : (int) $amounts->min();
    }

    /** Whether any of this owner's variants is in stock. */
    public function hasVariantInStock(): bool
    {
        return $this->variants->contains(fn (Variant $variant): bool => $variant->isInStock());
    }

    /** Combined stock level across this owner's variants. */
    public function variantStockTotal(): int
    {
        return (int) $this->variants->sum(fn (Variant $variant): int => $variant->stockLevel());
    }
}
