<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Events;

use InOtherShops\Commerce\Cart\Models\Cart;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class CartClaimed
{
    use Dispatchable;

    public function __construct(
        public Cart $cart,
        public Model $owner,
    ) {}
}
