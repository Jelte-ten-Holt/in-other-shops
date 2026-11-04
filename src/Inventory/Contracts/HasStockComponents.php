<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Contracts;

use InOtherShops\Inventory\DTOs\StockComponent;

/**
 * A sellable with no stock of its own whose stock is its components' — a
 * bundle (stock-holds brief §8 Q1). Whoever takes stock for it takes each
 * component's: the admin order's stock step in the package, and each
 * consumer's checkout `ReserveItems` step, so there is one expansion rule
 * rather than one per caller.
 *
 * Return the components that track stock, each at its per-unit quantity;
 * callers multiply by the number sold. A dangling component (a deleted
 * product) is left out, as the storefront's availability leaves it out.
 */
interface HasStockComponents
{
    /**
     * @return list<StockComponent>
     */
    public function stockComponents(): array;
}
