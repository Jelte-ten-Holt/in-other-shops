<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Contracts;

use InOtherShops\Currency\Enums\Currency;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Implemented by consumer models that own variants (typically a Product-shaped
 * model). The owner explicitly declares its axes (`options()`); which values
 * are in play is derived from the variants themselves.
 */
interface HasVariants
{
    /** @return MorphMany<\InOtherShops\Variants\Models\Variant, $this> */
    public function variants(): MorphMany;

    /**
     * The axes this owner varies by — the explicit declaration that lets the
     * admin define options before any variant exists.
     *
     * @return MorphToMany<\InOtherShops\Variants\Models\Option, $this>
     */
    public function options(): MorphToMany;

    public function hasVariants(): bool;

    /** The "from" price — lowest resolved unit price across the owner's variants. */
    public function lowestVariantPrice(Currency $currency): ?int;

    public function hasVariantInStock(): bool;

    public function variantStockTotal(): int;
}
