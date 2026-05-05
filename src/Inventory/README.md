# Inventory Domain

Stock tracking for any model via polymorphic `HasStock` / `InteractsWithStock`. Tracks a materialized `stock_level` on `StockItem` for fast reads, an append-only `StockMovement` ledger for audit, and a separate `StockReservation` entity for the pending/confirmed/released lifecycle.

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

**`StockMovement`** — **append-only** ledger entry. Every stock delta creates exactly one movement; movements are never updated.

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `stock_item_id` | FK | parent stock item (cascadeOnDelete) |
| `quantity` | integer | signed — positive for additions, negative for deductions |
| `reason` | string | enum value (received, restock, sold, reserved, released, adjusted) |
| `description` | string, nullable | optional free-text description |
| `reference_type` / `reference_id` | morph | upstream model that caused the delta (e.g. order line) |
| `source` | string, nullable | where the movement originated (e.g. dashboard, checkout) |
| `created_at` | timestamp | only — `const UPDATED_AT = null` enforces immutability |

**`StockReservation`** — lifecycle entity for pending/confirmed/released reservations. Mutable (status flips over its lifetime) but the ledger movements it points at are not.

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `stock_item_id` | FK | parent stock item (cascadeOnDelete) |
| `reserve_movement_id` | FK, unique | the `-X Reserved` ledger entry that opened the reservation |
| `release_movement_id` | FK, nullable | the `+X Released` ledger entry, set when released |
| `quantity` | unsigned int | always positive; direction is implied by lifecycle |
| `status` | string | `pending` / `confirmed` / `released` |
| `reserved_until` | timestamp, nullable | TTL for automatic expiry |
| `resolved_at` | timestamp, nullable | when the reservation transitioned out of `pending` |
| `reference_type` / `reference_id` | morph | upstream reference (same morph as the reserve movement) |
| `description` / `source` | string, nullable | mirrors the reserve movement |
| `timestamps` | | |

Indexes: `unique(reserve_movement_id)`, `(status, reserved_until)` for the expiry sweep.

Helper: `StockReservation::isExpired()` — `status = Pending && reserved_until < now`.

### Enums

- **`StockMovementReason`** — `received`, `restock`, `sold`, `reserved`, `released`, `adjusted`.
- **`ReservationStatus`** — `pending`, `confirmed`, `released`. `isResolved()` returns true for non-pending.

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

> **Stock writes go through these actions only.** Never write to `StockItem::stock_level` directly via Eloquent (`$item->stock_level = 50`), Eloquent's `update()`, raw `DB::table('stock_items')->update(...)`, or any other path. The actions are the single chokepoint that maintains the StockMovement ledger, dispatches StockAdjusted/StockReleased events, and (with the planned Stock value object) propagates writes across `LocaleGroup` siblings when `shares_inventory=true`. Bypassing them silently corrupts the audit trail and breaks shared-inventory propagation.
>
> **Documented exception:** seeders may create initial `StockItem` rows directly with a starting `stock_level` to avoid polluting the audit ledger with synthetic `received` movements. Anywhere outside seeders, use the actions.

- **`AdjustStock`** — finds or creates the StockItem, writes one StockMovement, updates `stock_level`. Single DB transaction. Returns the StockMovement. Validates `source` against `config('inventory.sources')`. **Use this for any non-reservation stock change** (received shipment, manual correction, restock).
- **`ReserveStock`** — inside a transaction: decrements stock via `AdjustStock` with `reason=Reserved` **and** creates a `StockReservation` row (`status=Pending`) pointing at that movement. Returns the `StockReservation`. Dispatches `ReservationCreated`.
- **`ConfirmReservation`** — transitions all pending reservations for a given reference to `Confirmed`. **No new movement**; stock already decremented at reserve time. The original `Reserved` ledger entry stays untouched. Dispatches `ReservationConfirmed` per transition.
- **`ReleaseReservation`** — releases a single reservation: appends a `+X Released` movement via `AdjustStock`, transitions the reservation `Pending → Released`, sets `release_movement_id`. Locked select + `status=Pending` guard makes it safe under concurrency. Dispatches `ReservationReleased` + `StockReleased`.
- **`ReleaseExpiredReservations`** — finds pending reservations past `reserved_until` and invokes `ReleaseReservation` for each. Idempotent under concurrent workers.

### Commands

- **`inventory:release-expired`** — runs `ReleaseExpiredReservations`. Scheduled every 5 minutes via the service provider.

### Events

- **`StockAdjusted`** — every stock delta. Carries the `StockMovement` and updated `StockItem`. Fires from `AdjustStock`.
- **`StockReleased`** — a reservation was released (the ledger side). Carries the `StockReservation` and the new release `StockMovement`.
- **`ReservationCreated`** / **`ReservationConfirmed`** / **`ReservationReleased`** — reservation lifecycle transitions. Each carries the `StockReservation`.

### Config

```php
// config/inventory.php
'sources' => [
    'dashboard' => 'Dashboard',
    'checkout' => 'Checkout',
    'import' => 'Import',
],
'reservation_ttl' => env('INVENTORY_RESERVATION_TTL', 30),  // minutes
'models' => [
    'stock_item' => InOtherShops\Inventory\Models\StockItem::class,
    'stock_movement' => InOtherShops\Inventory\Models\StockMovement::class,
    'stock_reservation' => InOtherShops\Inventory\Models\StockReservation::class,
],
```

### Design Decisions

- **Ledger is append-only, reservation has a lifecycle.** Mixing both into one table forces rewriting history (flipping `reason` from `reserved` to `sold`/`released`) which destroys the audit trail. The split puts immutability where it belongs (the ledger) and mutability where it belongs (an entity with a state machine).
- **`ConfirmReservation` writes no movement.** Confirmation changes reservation state, not stock level. Writing a `quantity=0` movement would be noise; the reservation's `resolved_at` + `status=Confirmed` carries the fact that confirmation happened, and the original `-X Reserved` movement remains the honest record of the decrement.
- **`ReleaseReservation` writes a new `+X Released` movement.** The original `-X Reserved` entry is never mutated; the +X entry is its compensating ledger pair.
- **Concurrency via `status=Pending` guard + `lockForUpdate`.** Idempotent expiry runs; double-release is impossible.
- **`unique(reserve_movement_id)` on reservations.** One reservation per reserve movement — enforced at the schema level.
- **morphOne StockItem** — one item per stockable. Multi-location inventory would add `location_id` later.
- **Materialized `stock_level`** — avoids summing the ledger on every read. Updated atomically via `increment()` in the same transaction as movement creation.

## Dependencies

None — independent domain.

## Filament Integration

- **`InventorySchema`** — `stockSection()` returns a collapsible Section with stock level display, low-stock threshold, and inline stock adjustment (quantity, reason, description). Uses `fillFormData()`/`saveFormData()` for manual sync.
- **`StockMovementsTable`** — Livewire table of all movements for a stockable, with sortable columns (date, quantity, reason, source, description).

## Future

- Low-stock notification events.
- Multi-location inventory support.
- Reservation log subscriber (package-ships pattern, once Phase D lands).
