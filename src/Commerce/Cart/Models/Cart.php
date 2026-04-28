<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Models;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Database\Factories\CartFactory;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Shipping\Contracts\HasShippability;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Cart extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new CartFactory;
    }

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'expires_at' => 'datetime',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): HasMany
    {
        return $this->hasMany(Commerce::cartItem());
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
