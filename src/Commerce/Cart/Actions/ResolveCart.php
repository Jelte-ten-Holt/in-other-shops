<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Actions;

use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Database\Eloquent\Model;

final class ResolveCart
{
    /**
     * Find or create a cart by owner or session token.
     *
     * Owner takes precedence: if an owner is provided, the session token is ignored.
     */
    public function __invoke(
        Currency $currency,
        ?string $sessionToken = null,
        ?Model $owner = null,
    ): Cart {
        if ($owner !== null) {
            return $this->resolveByOwner($owner, $currency);
        }

        if ($sessionToken !== null) {
            return $this->resolveBySession($sessionToken, $currency);
        }

        throw new \InvalidArgumentException('Either a session token or an owner must be provided.');
    }

    private function resolveByOwner(Model $owner, Currency $currency): Cart
    {
        return Cart::firstOrCreate(
            [
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
            ],
            ['currency' => $currency],
        );
    }

    private function resolveBySession(string $sessionToken, Currency $currency): Cart
    {
        return Cart::firstOrCreate(
            ['session_token' => $sessionToken],
            ['currency' => $currency],
        );
    }
}
