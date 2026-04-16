<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Actions;

use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Commerce\Cart\Events\CartUpdated;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Database\Eloquent\Model;

final class AddToCart
{
    public function __invoke(Cart $cart, HasCart&Model $cartable, int $quantity = 1): CartItem
    {
        $item = $this->findOrCreateItem($cart, $cartable, $quantity);

        CartUpdated::dispatch($cart);

        return $item;
    }

    private function findOrCreateItem(Cart $cart, HasCart&Model $cartable, int $quantity): CartItem
    {
        $item = $cart->items()
            ->where('cartable_type', $cartable->getMorphClass())
            ->where('cartable_id', $cartable->getKey())
            ->first();

        if ($item !== null) {
            $item->increment('quantity', $quantity);

            return $item->refresh();
        }

        $currency = $this->resolveCurrency($cart);

        return $cart->items()->create([
            'cartable_type' => $cartable->getMorphClass(),
            'cartable_id' => $cartable->getKey(),
            'quantity' => $quantity,
            'unit_price' => $cartable->getCartableUnitPrice($currency),
            'currency' => $currency,
        ]);
    }

    private function resolveCurrency(Cart $cart): Currency
    {
        if ($cart->currency instanceof Currency) {
            return $cart->currency;
        }

        return Currency::from(config('commerce.cart.api.default_currency', 'EUR'));
    }
}
