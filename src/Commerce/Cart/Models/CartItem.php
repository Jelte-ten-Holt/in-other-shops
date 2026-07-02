<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Models;

use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Database\Factories\CartItemFactory;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CartItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static string $factory = CartItemFactory::class;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'currency' => Currency::class,
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Commerce::cart());
    }

    public function cartable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The currency this line prices in: its own stamped currency, else its
     * cart's, else the default.
     */
    public function effectiveCurrency(): Currency
    {
        if ($this->currency instanceof Currency) {
            return $this->currency;
        }

        return $this->cart?->effectiveCurrency() ?? Cart::defaultCurrency();
    }

    /**
     * The unit price to charge for this line: the snapshot captured when it was
     * added, falling back to the cartable's live price, or null when neither is
     * available. The single home for the snapshot→live→null rule — was
     * duplicated between `CartResource::subtotal` and `CartItemResource`, so a
     * line total and the cart subtotal could compute it differently.
     */
    public function effectiveUnitPrice(Currency $currency): ?int
    {
        if ($this->unit_price !== null) {
            return $this->unit_price;
        }

        $cartable = $this->cartable;

        return $cartable instanceof HasCart
            ? $cartable->getCartableUnitPrice($currency)
            : null;
    }
}
