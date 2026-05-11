<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Inventory\DTOs\Stock;
use InOtherShops\Inventory\Exceptions\RawStockMutationException;

/**
 * Inbound-only cast for `StockItem::stock_level`. Reads pass through
 * Eloquent's regular int cast (declared alongside this one on the model);
 * writes require a {@see Stock} value object — a raw int, string, or
 * anything else throws {@see RawStockMutationException}.
 *
 * `CastsInboundAttributes` (no `get()`) is deliberate. A symmetric class
 * cast caches the *typed* value the caller passed in, so a `set(new Stock)`
 * would surface as `Stock` on subsequent reads of the same in-memory model
 * — breaking every existing reader that treats stock_level as int. By
 * staying inbound-only this cast can guard the write boundary without
 * disturbing the read shape anywhere else in the codebase.
 */
final class StockCast implements CastsInboundAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        if ($value instanceof Stock) {
            return $value->level;
        }

        throw RawStockMutationException::forValue($value);
    }
}
