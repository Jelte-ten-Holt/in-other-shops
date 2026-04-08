<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Actions;

use InOtherShops\Commerce\Cart\Events\CartUpdated;
use InOtherShops\Commerce\Cart\Models\CartItem;

final class RemoveFromCart
{
    public function __invoke(CartItem $item): void
    {
        $cart = $item->cart;

        $item->delete();

        CartUpdated::dispatch($cart);
    }
}
