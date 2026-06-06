<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Inventory\DTOs\Stock;
use InOtherShops\Inventory\Exceptions\RawStockMutationException;

/**
 * Inbound-only cast for `StockItem::stock_level`. Writes require a {@see Stock}
 * value object — a raw int, string, or anything else throws
 * {@see RawStockMutationException}. Reads are NOT cast: `stock_level` comes back
 * as the raw database attribute (an int on most drivers; a numeric string under
 * PDO emulated-prepares). Callers comparing or returning it lean on PHP's
 * numeric coercion; there is intentionally no read-side int cast (see below).
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
