<?php

declare(strict_types=1);

namespace InOtherShops\Tracking\FlowChains\Steps;

use Illuminate\Database\Eloquent\Model;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\FlowChain\AbstractFlowStep;
use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\Tracking\Contracts\HasCheckoutAttribution;
use InOtherShops\Tracking\Tracking;

/**
 * Copies cart_item_attributions onto order_line_attributions so attribution
 * survives the cart. Carts are cleared once payment lands; orders are the
 * durable record, so this is the step that makes the data worth keeping.
 *
 * # Where it goes in the chain
 *
 * After the step that creates the order and its lines, and before anything
 * that mutates or clears the cart. It reads both sides, writes only its own
 * table, and takes the payload through HasCheckoutAttribution — checkout
 * chains are app-owned in every consumer, so the payload class itself cannot
 * be named here.
 *
 * # Matching
 *
 * Each order line is matched to its source cart item by orderable identity
 * (`cartable_type`+`cartable_id` == `orderable_type`+`orderable_id`). The
 * package's AddToCart guarantees one cart_item per (cart, cartable), so the
 * match is unambiguous rather than best-effort.
 *
 * # Failure mode
 *
 * Lines with no attribution are skipped — most lines, wherever a consumer's
 * capture sites cover only part of the storefront. Write failures are NOT
 * swallowed: this runs inside the checkout transaction, and a half-written
 * snapshot on a real order is worse than a rolled-back checkout the shopper
 * can retry. That is the opposite call from RecordCartItemAttribution, and
 * deliberately so — one is a cart add, this one is the money path.
 *
 * @reads attributionCart, attributionOrder
 */
final class SnapshotCartItemAttributions extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void
    {
        if (! $payload instanceof HasCheckoutAttribution) {
            throw new \InvalidArgumentException(sprintf(
                '%s requires a payload implementing %s; %s does not implement it.',
                self::class,
                HasCheckoutAttribution::class,
                $payload::class,
            ));
        }

        $cart = $payload->attributionCart();
        $order = $payload->attributionOrder();

        // No order yet means this step is wired before the one that creates it.
        // Nothing to snapshot onto; skip rather than assert on ordering.
        if ($order === null) {
            return;
        }

        $cart->loadMissing('items');
        $order->loadMissing('lines');

        if ($cart->items->isEmpty() || $order->lines->isEmpty()) {
            return;
        }

        $attributions = Tracking::cartItemAttribution()::query()
            ->whereIn('cart_item_id', $cart->items->modelKeys())
            ->get()
            ->keyBy('cart_item_id');

        if ($attributions->isEmpty()) {
            return;
        }

        $model = Tracking::orderLineAttribution();

        foreach ($order->lines as $orderLine) {
            $cartItem = $this->matchCartItem($cart->items, $orderLine);

            if ($cartItem === null) {
                continue;
            }

            $attribution = $attributions->get($cartItem->id);

            if ($attribution === null) {
                continue;
            }

            $model::query()->create([
                'order_line_id' => $orderLine->id,
                'source_type' => $attribution->source_type,
                'source_id' => $attribution->source_id,
                'created_at' => now(),
            ]);
        }
    }

    public static function expectedInputs(): array
    {
        return [
            'attributionCart' => \InOtherShops\Commerce\Cart\Models\Cart::class,
            'attributionOrder' => '?'.\InOtherShops\Commerce\Order\Models\Order::class,
        ];
    }

    /**
     * @param  iterable<CartItem>  $cartItems
     */
    private function matchCartItem(iterable $cartItems, Model $orderLine): ?CartItem
    {
        foreach ($cartItems as $cartItem) {
            if ($cartItem->cartable_type === $orderLine->orderable_type
                && (int) $cartItem->cartable_id === (int) $orderLine->orderable_id) {
                return $cartItem;
            }
        }

        return null;
    }
}
