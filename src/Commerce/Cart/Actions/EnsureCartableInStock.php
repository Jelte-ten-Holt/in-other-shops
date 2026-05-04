<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Actions;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Inventory\Contracts\HasStock;

/**
 * Refuses cart writes that would oversell a stockable cartable.
 *
 * Skips silently if the cartable doesn't implement HasStock, doesn't track
 * stock, or explicitly allows backorder. Otherwise throws DomainException
 * when the requested running quantity exceeds the current stock level.
 *
 * Cross-domain note: this action is the single place Commerce → Inventory
 * coupling is allowed in the Cart domain. The dependency is soft (only
 * fires when the cartable opts into HasStock) and goes through the
 * contract — Commerce never imports Inventory model classes.
 */
final class EnsureCartableInStock
{
    public function __invoke(Model&HasCart $cartable, int $requestedQuantity): void
    {
        if (! $cartable instanceof HasStock) {
            return;
        }

        if (! $cartable->tracksStock()) {
            return;
        }

        if ($cartable->allowsBackorder()) {
            return;
        }

        $available = $cartable->stockLevel();

        if ($requestedQuantity <= $available) {
            return;
        }

        throw new DomainException($this->message($cartable, $available));
    }

    private function message(Model&HasCart $cartable, int $available): string
    {
        $name = $cartable->getCartableLabel();

        if ($available <= 0) {
            return "{$name} is out of stock.";
        }

        return "{$name}: only {$available} in stock.";
    }
}
