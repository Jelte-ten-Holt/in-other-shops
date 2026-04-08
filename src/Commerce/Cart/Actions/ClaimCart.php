<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Actions;

use InOtherShops\Commerce\Cart\Events\CartClaimed;
use InOtherShops\Commerce\Cart\Models\Cart;
use Illuminate\Database\Eloquent\Model;

final class ClaimCart
{
    public function __invoke(Cart $cart, Model $owner): Cart
    {
        $cart->update([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'session_token' => null,
        ]);

        $cart->refresh();

        CartClaimed::dispatch($cart, $owner);

        return $cart;
    }
}
