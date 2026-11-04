<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Exceptions;

use InOtherShops\Inventory\Models\StockHold;

/**
 * The hold an admin order takes from was drained or topped up between the page
 * showing each line's split and the save (stock-holds brief §4.2): under the
 * locks the split came out different, so the save is refused rather than
 * committing a split the admin never saw. Load-to-save is the wide window; it
 * re-validates.
 */
final class StockHoldChangedException extends CommerceException
{
    public static function for(StockHold $hold): self
    {
        return new self("Stock hold \"{$hold->name}\" changed while the order was being written. Review the lines and save again.");
    }
}
