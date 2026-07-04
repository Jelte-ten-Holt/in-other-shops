<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Concerns;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Exceptions\CartReferencesCartableException;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait InteractsWithCart
{
    /**
     * Block deleting a cart-able while a live cart still references it — a
     * deleted cartable would strand those cart lines (order lines snapshot, so
     * they stay safe). "Live" = a cart whose `expires_at` is null or in the
     * future; expired guest carts are pruned and don't block. Applies to every
     * cart-able; disable per-consumer via `commerce.cart.guard_cartable_deletion`.
     */
    public static function bootInteractsWithCart(): void
    {
        static::deleting(function (Model $cartable): void {
            if (! config('commerce.cart.guard_cartable_deletion', true)) {
                return;
            }

            $liveCartReferences = $cartable->cartItems()
                ->whereHas('cart', function (Builder $query): void {
                    $query->where(function (Builder $live): void {
                        $live->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
                })
                ->count();

            if ($liveCartReferences === 0) {
                return;
            }

            // getCartableLabel() is provided by this trait, so every cart-able has it.
            throw CartReferencesCartableException::forCartable(
                $cartable->getCartableLabel(),
                $liveCartReferences,
            );
        });
    }

    public function cartItems(): MorphMany
    {
        $model = Commerce::cartItem();

        return $this->morphMany($model, 'cartable');
    }

    /**
     * Default label: the model's `name`, falling back to `slug`, then to
     * "{morph alias} #{key}". The fallbacks keep the `string` contract honest
     * for cartables whose name is null or empty — e.g. a translated model
     * with no name translation in any locale — instead of letting a null
     * `name` escape as a TypeError that 500s every cart render containing
     * the item (bianka AUDIT-2026-07-04 BUG-3).
     */
    public function getCartableLabel(): string
    {
        $name = $this->name;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $slug = $this->slug;

        if (is_string($slug) && $slug !== '') {
            return $slug;
        }

        return $this->getMorphClass().' #'.$this->getKey();
    }

    public function getCartableDescription(): ?string
    {
        return $this->description ?? null;
    }

    public function getCartableUnitPrice(Currency $currency): ?int
    {
        return null;
    }
}
