<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Models;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Database\Factories\CartFactory;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Shipping\Contracts\HasShippability;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Cart extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static string $factory = CartFactory::class;

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The API default cart currency. The home for the
     * `commerce.cart.api.default_currency` literal on the cart path — with
     * one known exception: Variants/Filament/VariantsSchema reads the config
     * key directly (admin price fields, outside the cart object graph). A
     * future move to a `currency.default` home (T-D3) must repoint BOTH.
     */
    public static function defaultCurrency(): Currency
    {
        return Currency::from(config('commerce.cart.api.default_currency', 'EUR'));
    }

    /**
     * The currency this cart prices in: its own stamped currency, else the
     * default. Was duplicated across the cart HTTP resources and the
     * add-to-cart step.
     */
    public function effectiveCurrency(): Currency
    {
        return $this->currency instanceof Currency
            ? $this->currency
            : self::defaultCurrency();
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): HasMany
    {
        // Deterministic insertion order (by id). Checkout relies on the cart's
        // item order matching the priced breakdown's line order — without an
        // explicit order, the DB is free to return rows in any order and an
        // order line can be stamped with another line's VAT rate (audit G6).
        return $this->hasMany(Commerce::cartItem())->orderBy('id');
    }

    public function requiresShipping(): bool
    {
        $this->loadMissing('items.cartable');

        return $this->items->contains(function (CartItem $item): bool {
            $cartable = $item->cartable;

            return $cartable instanceof HasShippability
                ? $cartable->requiresShipping()
                : true;
        });
    }
}
