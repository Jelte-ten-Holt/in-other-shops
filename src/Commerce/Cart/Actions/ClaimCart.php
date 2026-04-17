<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Actions;

use InOtherShops\Commerce\Cart\Events\CartClaimed;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Commerce;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Claim a guest cart for an owner. If the owner already has a cart, the
 * guest cart's items are merged into it (quantities sum on same cartable)
 * and the guest cart is deleted. Otherwise the guest cart's ownership is
 * flipped to the owner.
 *
 * The `(owner_type, owner_id)` unique index on carts is the schema-level
 * invariant; merging before any write keeps the invariant from ever firing
 * in practice.
 */
final class ClaimCart
{
    public function __invoke(Cart $guestCart, Model $owner): Cart
    {
        return DB::transaction(function () use ($guestCart, $owner): Cart {
            $existing = $this->findOwnerCart($owner);

            $cart = $existing === null
                ? $this->claim($guestCart, $owner)
                : $this->merge($guestCart, $existing);

            CartClaimed::dispatch($cart, $owner);

            return $cart;
        });
    }

    private function findOwnerCart(Model $owner): ?Cart
    {
        /** @var Cart|null */
        return Commerce::cart()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->first();
    }

    private function claim(Cart $guestCart, Model $owner): Cart
    {
        $guestCart->update([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'session_token' => null,
        ]);

        return $guestCart->refresh();
    }

    private function merge(Cart $guestCart, Cart $existing): Cart
    {
        foreach ($guestCart->items as $guestItem) {
            $match = $existing->items()
                ->where('cartable_type', $guestItem->cartable_type)
                ->where('cartable_id', $guestItem->cartable_id)
                ->first();

            if ($match !== null) {
                $match->increment('quantity', $guestItem->quantity);

                continue;
            }

            $existing->items()->create([
                'cartable_type' => $guestItem->cartable_type,
                'cartable_id' => $guestItem->cartable_id,
                'quantity' => $guestItem->quantity,
                'unit_price' => $guestItem->unit_price,
                'currency' => $guestItem->currency,
            ]);
        }

        $guestCart->delete();

        return $existing->fresh('items');
    }
}
