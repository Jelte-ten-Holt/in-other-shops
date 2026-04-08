<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Actions;

use InOtherShops\Commerce\Cart\Events\CartUpdated;
use InOtherShops\Commerce\Cart\Models\CartItem;

final class UpdateCartItemQuantity
{
    /**
     * Set the quantity of a cart item. Removes the item if quantity is zero or less.
     *
     * Returns the updated item, or null if it was removed.
     */
    public function __invoke(CartItem $item, int $quantity): ?CartItem
    {
        if ($quantity <= 0) {
            return $this->removeItem($item);
        }

        return $this->updateQuantity($item, $quantity);
    }

    private function removeItem(CartItem $item): null
    {
        $cart = $item->cart;

        $item->delete();

        CartUpdated::dispatch($cart);

        return null;
    }

    private function updateQuantity(CartItem $item, int $quantity): CartItem
    {
        $item->update(['quantity' => $quantity]);

        CartUpdated::dispatch($item->cart);

        return $item->refresh();
    }
}
