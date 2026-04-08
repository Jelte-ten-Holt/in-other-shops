<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Actions;

use InOtherShops\Commerce\Cart\Events\CartCleared;
use InOtherShops\Commerce\Cart\Models\Cart;

final class ClearCart
{
    public function __invoke(Cart $cart): void
    {
        $cart->items()->delete();

        CartCleared::dispatch($cart);
    }
}
