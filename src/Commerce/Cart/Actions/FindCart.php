<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Actions;

use InOtherShops\Commerce\Cart\Models\Cart;
use Illuminate\Database\Eloquent\Model;

/**
 * The read-only twin of {@see ResolveCart}: look a cart up, never create one.
 *
 * Read paths must use this. A shared "cart badge" prop rendered on every page
 * went through ResolveCart, so every anonymous page view — every crawler hit —
 * minted a `carts` row via `firstOrCreate`. Creation belongs to the paths that
 * actually put something in a cart.
 */
final class FindCart
{
    /**
     * Owner takes precedence: if an owner is provided, the session token is ignored.
     * Mirrors ResolveCart's precedence so the two can never disagree about which
     * cart "the current cart" is.
     */
    public function __invoke(
        ?string $sessionToken = null,
        ?Model $owner = null,
    ): ?Cart {
        if ($owner !== null) {
            return $this->findByOwner($owner);
        }

        if ($sessionToken !== null) {
            return $this->findBySession($sessionToken);
        }

        throw new \InvalidArgumentException('Either a session token or an owner must be provided.');
    }

    private function findByOwner(Model $owner): ?Cart
    {
        return Cart::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->first();
    }

    private function findBySession(string $sessionToken): ?Cart
    {
        return Cart::query()
            ->where('session_token', $sessionToken)
            ->first();
    }
}
