<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Exceptions;

/**
 * Thrown by {@see \InOtherShops\Inventory\Casts\StockCast} when a caller
 * tries to assign anything other than a {@see \InOtherShops\Inventory\DTOs\Stock}
 * instance to `StockItem::stock_level`. The intent is to catch refactor-time
 * mistakes where someone bypasses AdjustStock with a direct Eloquent write —
 * `$item->stock_level = 50; $item->save();` — which silently skips the
 * StockMovement ledger, the StockAdjusted event, and (for HasLocaleGroup
 * models with shares_inventory=true) the sibling propagation.
 *
 * Wrapping the int in a Stock value object is the language-level
 * acknowledgement that the caller knows they're stepping outside the
 * AdjustStock chokepoint. Tests and factories pass `new Stock(N)`; runtime
 * writes go through AdjustStock, which also wraps internally.
 */
final class RawStockMutationException extends InventoryException
{
    public static function forValue(mixed $value): self
    {
        $type = get_debug_type($value);

        return new self(
            "StockItem::stock_level may only be assigned a Stock value object, got {$type}. "
            ."Route writes through InOtherShops\\Inventory\\Actions\\AdjustStock, "
            .'or — for fixture/factory setup — wrap the int in new Stock(N).'
        );
    }
}
