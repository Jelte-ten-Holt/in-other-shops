<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Actions;

use Illuminate\Database\Eloquent\Model;
use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Exceptions\InsufficientStockException;

/**
 * Refuses cart writes that would oversell a stockable cartable.
 *
 * Skips silently if the cartable doesn't implement HasStock, doesn't track
 * stock, or explicitly allows backorder. Otherwise throws
 * InsufficientStockException when the requested running quantity exceeds
 * the current stock level.
 *
 * Cross-domain note: this action is the single place Commerce → Inventory
 * coupling is allowed in the Cart domain. The dependency is soft (only
 * fires when the cartable opts into HasStock) and goes through the
 * contract + the shared Inventory exception type — Commerce never imports
 * Inventory model classes.
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

        throw InsufficientStockException::forCart($cartable->getCartableLabel(), $available);
    }
}
