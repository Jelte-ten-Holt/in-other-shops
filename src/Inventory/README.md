# Inventory Domain

Stock tracking for any model via polymorphic `HasStock` / `InteractsWithStock`. Tracks a materialized `stock_level` on `StockItem` for fast reads, with a `StockMovement` ledger for full audit trail.

## Architecture

### Models

**`StockItem`** — one per stockable model (morphOne). Holds the current `stock_level` and an optional `low_stock_threshold`.

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `stockable_type` | string | morph type |
| `stockable_id` | bigint | morph ID |
| `stock_level` | integer | materialized current stock (can be negative) |
| `low_stock_threshold` | unsigned int, nullable | threshold for low-stock alerts |
| `timestamps` | | |

Unique constraint on `[stockable_type, stockable_id]`.

**`StockMovement`** — audit ledger entry. Every stock change creates a movement.

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `stock_item_id` | FK | parent stock item (cascadeOnDelete) |
| `quantity` | integer | signed — positive for additions, negative for deductions |
| `reason` | string | enum value (received, sold, reserved, released, adjusted) |
| `description` | string, nullable | optional free-text description |
| `reference_type` | string, nullable | morph type of the model that caused this movement (e.g. order) |
| `reference_id` | bigint, nullable | morph ID |
| `source` | string, nullable | where the movement originated (e.g. dashboard, checkout) |
| `reserved_until` | timestamp, nullable | TTL for reservations — after this time, the reservation expires |
| `timestamps` | | |

Helpers: `isExpired()` — checks if `reserved_until` is in the past.

### Enum

**`StockMovementReason`** — `received`, `sold`, `reserved`, `released`, `adjusted`. Has a `label()` method for display.

### Contract & Trait

```php
interface HasStock
{
    public function stockItem(): MorphOne;
    public function stockLevel(): int;
    public function isInStock(): bool;
}
```

`InteractsWithStock` implements all methods. `stockLevel()` returns 0 if no StockItem exists. `isInStock()` returns `stockLevel() > 0`.

### Actions

- **`AdjustStock`** — finds or creates the StockItem, creates a StockMovement, updates `stock_level`. All in a single DB transaction. Returns the StockMovement. Accepts optional `?Model $reference`, `?string $source`, `?CarbonInterface $reservedUntil`. Validates `source` against `config('inventory.sources')`.
- **`ReserveStock`** — wraps AdjustStock with `reserved` reason and negative quantity. Accepts optional `?Model $reference`, `?string $source`, `?CarbonInterface $reservedUntil`.
- **`ReleaseStock`** — wraps AdjustStock with `released` reason and positive quantity. Accepts optional `?Model $reference`, `?string $source`.
- **`ConfirmReservation`** — finds all reserved movements for a given reference model and converts them to `sold`. Stock level is unchanged (the reservation already decremented it). Clears `reserved_until`. Optionally updates description.
- **`ReleaseExpiredReservations`** — finds all reserved movements where `reserved_until` is in the past, releases the stock back (positive adjustment), marks the original movement as `released`. Dispatches `StockReleased` event for each.

### Commands

- **`inventory:release-expired`** — runs `ReleaseExpiredReservations`. Scheduled every 5 minutes via the service provider.

### Events

- **`StockAdjusted`** — dispatched after every stock adjustment (after transaction commits). Carries the `StockMovement` and updated `StockItem`. Fired by `AdjustStock` (and therefore also by `ReserveStock` and `ReleaseStock`, which wrap it).
- **`StockReleased`** — dispatched when an expired reservation is released. Carries the original reservation movement and the new release movement.

### Config

```php
// config/inventory.php
'sources' => [
    'dashboard' => 'Dashboard',
    'checkout' => 'Checkout',
    'import' => 'Import',
],
'reservation_ttl' => env('INVENTORY_RESERVATION_TTL', 30),  // minutes
```

When `sources` is configured, `AdjustStock` validates the `source` parameter against the keys. When empty/null, any value is accepted.

### Design Decisions

- **morphOne** — one StockItem per stockable. Multi-location inventory would add a `location_id` to StockItem later.
- **Materialized `stock_level`** — avoids summing movements on every read. Updated atomically via `increment()` in the same transaction as movement creation.
- **Polymorphic reference** — stock movements can be linked back to the model that caused them (e.g. an Order). This enables `ConfirmReservation` to find all reservations for a specific order and convert them to sold. The Inventory domain never imports Order — it only knows about the morph.
- **Source tracking** — `source` records where the movement originated (dashboard, checkout, import). Validated against config so sources stay consistent. Displayed in Filament's StockMovementsTable.
- **TTL-based expiry** — reservations with `reserved_until` are automatically released by a scheduled command. Reservations without a TTL never expire (manual release only).
- **No availability check** — `stockLevel()` on the trait is sufficient. The consuming model decides what "available" means (e.g., closeout products may sell at stock_level 0).

## Dependencies

None — independent domain.

## Filament Integration

- **`InventorySchema`** — `stockSection()` returns a collapsible Section with stock level display, low-stock threshold, and inline stock adjustment (quantity, reason, description). Uses `fillFormData()`/`saveFormData()` for manual sync, following the Translation/Media pattern.
- **`StockMovementsTable`** — Livewire table of all movements for a stockable, with sortable columns (date, quantity, reason badge, source badge, description).

## Future

- Low-stock notification events
- Multi-location inventory support
