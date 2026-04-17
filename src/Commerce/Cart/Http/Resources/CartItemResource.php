<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Http\Resources;

use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CartItem */
final class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cartable = $this->resource->cartable;
        $currency = $this->resolveCurrency();
        $unitPrice = $this->resolveUnitPrice($cartable, $currency);
        $lineTotal = $unitPrice !== null ? $unitPrice * $this->resource->quantity : null;

        return [
            'id' => $this->resource->id,
            'quantity' => $this->resource->quantity,
            'cartable' => [
                'type' => $this->resource->cartable_type,
                'id' => $this->resource->cartable_id,
                'label' => $cartable instanceof HasCart ? $cartable->getCartableLabel() : null,
                'description' => $cartable instanceof HasCart ? $cartable->getCartableDescription() : null,
            ],
            'unit_price' => $unitPrice !== null ? [
                'amount' => $unitPrice,
                'currency' => $currency->value,
                'formatted' => $currency->format($unitPrice),
            ] : null,
            'line_total' => $lineTotal !== null ? [
                'amount' => $lineTotal,
                'currency' => $currency->value,
                'formatted' => $currency->format($lineTotal),
            ] : null,
        ];
    }

    private function resolveCurrency(): Currency
    {
        if ($this->resource->currency instanceof Currency) {
            return $this->resource->currency;
        }

        $cart = $this->resource->cart;
        if ($cart && $cart->currency instanceof Currency) {
            return $cart->currency;
        }

        return Currency::from(config('commerce.cart.api.default_currency', 'EUR'));
    }

    private function resolveUnitPrice(mixed $cartable, Currency $currency): ?int
    {
        if ($this->resource->unit_price !== null) {
            return $this->resource->unit_price;
        }

        if (! $cartable instanceof HasCart) {
            return null;
        }

        return $cartable->getCartableUnitPrice($currency);
    }
}
