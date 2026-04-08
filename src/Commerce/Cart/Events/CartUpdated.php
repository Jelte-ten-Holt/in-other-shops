<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Events;

use InOtherShops\Commerce\Cart\Models\Cart;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class CartUpdated
{
    use Dispatchable;

    public function __construct(
        public Cart $cart,
    ) {}
}
