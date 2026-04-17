<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Http\Resources;

use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Cart */
final class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->resource->items;
        $currency = $this->resource->currency instanceof Currency
            ? $this->resource->currency
            : Currency::from(config('commerce.cart.api.default_currency', 'EUR'));

        $itemResources = CartItemResource::collection($items);
        $subtotal = $this->subtotal($items, $currency);
        $itemCount = (int) $items->sum('quantity');

        return [
            'id' => $this->resource->id,
            'currency' => $currency->value,
            'item_count' => $itemCount,
            'items' => $itemResources,
            'subtotal' => [
                'amount' => $subtotal,
                'currency' => $currency->value,
                'formatted' => $currency->format($subtotal),
            ],
        ];
    }

    private function subtotal($items, Currency $currency): int
    {
        $total = 0;

        foreach ($items as $item) {
            $unitPrice = $item->unit_price;

            if ($unitPrice === null) {
                $cartable = $item->cartable;

                if (! $cartable instanceof HasCart) {
                    continue;
                }

                $unitPrice = $cartable->getCartableUnitPrice($currency);
            }

            if ($unitPrice === null) {
                continue;
            }

            $total += $unitPrice * $item->quantity;
        }

        return $total;
    }
}
