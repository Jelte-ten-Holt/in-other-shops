<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Order\DTOs\PreOrderRecipient;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\OrderLine;

/**
 * Resolve the people who pre-ordered a given purchasable and could be notified
 * about it (e.g. a release announcement).
 *
 * Deliberately **shipment-agnostic**: it answers "who pre-ordered X on a live
 * order", not "who is still awaiting delivery". That keeps the query inside
 * Commerce instead of reaching into Shipping (a dependency the package is
 * trying to shed, not deepen). A consumer that wants to exclude already-shipped
 * recipients filters on its own side, where coupling to Shipping is free.
 *
 * Guests (no customer record) are included via the order's own email.
 * Recipients are deduplicated by normalized email — a customer with several
 * pre-order lines or orders for the product is returned once — preferring the
 * record that carries a customer id.
 */
final class ResolvePreOrderAudience
{
    /**
     * @return Collection<int, PreOrderRecipient>
     */
    public function __invoke(Model $purchasable): Collection
    {
        return $this->preOrderLinesFor($purchasable)
            ->map(fn (OrderLine $line): ?PreOrderRecipient => $this->toRecipient($line))
            ->filter()
            ->sortByDesc(fn (PreOrderRecipient $recipient): bool => $recipient->customerId !== null)
            ->unique(fn (PreOrderRecipient $recipient): string => $recipient->email)
            ->values();
    }

    /**
     * @return Collection<int, OrderLine>
     */
    private function preOrderLinesFor(Model $purchasable): Collection
    {
        return Commerce::orderLine()::query()
            ->preOrder()
            ->whereMorphedTo('orderable', $purchasable)
            ->whereHas('order', fn ($query) => $query->where('status', '!=', OrderStatus::Cancelled->value))
            ->with(['order', 'order.customer'])
            ->get();
    }

    private function toRecipient(OrderLine $line): ?PreOrderRecipient
    {
        $order = $line->order;
        $customer = $order?->customer;

        $email = $order?->email ?? $customer?->email;

        if ($email === null) {
            return null;
        }

        return new PreOrderRecipient(
            email: mb_strtolower(trim($email)),
            name: $customer?->name,
            locale: $order?->locale,
            customerId: $order?->customer_id,
        );
    }
}
