<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\DTOs;

/**
 * Minimal value-object wrapper for a stock level. Exists so that writes
 * to `StockItem::stock_level` carry a type the language can enforce —
 * raw-int assignment is the footgun that silently bypasses the audit
 * ledger and the LocaleGroup-sibling propagation in {@see \InOtherShops\Inventory\Actions\AdjustStock}.
 *
 * Read side is unchanged: `$stockItem->stock_level` still returns int.
 * Write side requires `new Stock(N)`, so a caller doing
 * `$item->stock_level = 50; $item->save();` fails at the cast boundary
 * instead of corrupting state silently. Negative levels are allowed —
 * backorder paths legitimately push stock_level negative.
 */
final readonly class Stock
{
    public function __construct(
        public int $level,
    ) {}
}
