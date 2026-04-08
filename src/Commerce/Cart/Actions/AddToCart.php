<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Actions;

use InOtherShops\Commerce\Cart\Contracts\Cartable;
use InOtherShops\Commerce\Cart\Events\CartUpdated;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use Illuminate\Database\Eloquent\Model;

final class AddToCart
{
    public function __invoke(Cart $cart, Cartable&Model $cartable, int $quantity = 1): CartItem
    {
        $item = $this->findOrCreateItem($cart, $cartable, $quantity);

        CartUpdated::dispatch($cart);

        return $item;
    }

    private function findOrCreateItem(Cart $cart, Cartable&Model $cartable, int $quantity): CartItem
    {
        $item = $cart->items()
            ->where('cartable_type', $cartable->getMorphClass())
            ->where('cartable_id', $cartable->getKey())
            ->first();

        if ($item !== null) {
            $item->increment('quantity', $quantity);

            return $item->refresh();
        }

        return $cart->items()->create([
            'cartable_type' => $cartable->getMorphClass(),
            'cartable_id' => $cartable->getKey(),
            'quantity' => $quantity,
        ]);
    }
}
