<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Exceptions;

use InOtherShops\Inventory\Models\StockHold;

/**
 * A move into, out of or within a hold that was released — read from the
 * hold row under its lock, so a Release that committed while the move waited
 * is seen (stock-holds brief §4.1). A released hold is read-only.
 */
final class StockHoldReleasedException extends InventoryException
{
    public static function for(StockHold $hold): self
    {
        return new self("Stock hold \"{$hold->name}\" has been released and can no longer change.");
    }
}
