# In Other Shops — TODO

Package-level work, typically surfaced by consuming projects.

---

## Storefront

- [ ] Add stock availability to `BrowsableResource` (verify what's already exposed). Consumers currently re-derive stock in their own payloads — the browsable should include it directly.

## Inventory

- [ ] Add `Restock` case to `StockMovementReason` enum. Common user-facing word for inbound stock from suppliers; `Received` is broader. Surfaced while writing `ProductTest::stock_level_reflects_adjustments` in in-other-worlds.

## Taxonomy

- [ ] Add `position` column to `tags` table (migration) for symmetry with `categories`. Consuming projects should decide whether tags are ordered or flat — the package shouldn't pre-empt that choice. Include a `TagResource` reorder action to match the category UX.

## Logging

- [ ] Update Logging README. The "Wiring Up a New Log Subscriber" example uses `subscribe(Dispatcher $events)` + `$events->listen(...)`, but Laravel 11+ enables event auto-discovery by default — any public method in `app/Listeners/**` that type-hints an event is auto-wired. The explicit `subscribe()` + `Event::subscribe()` registration causes **double dispatch**. Document auto-discovery as the primary pattern; keep `subscribe()` only as a fallback for non-standard listener paths. Confirmed by [tests/Feature/Listeners/Logging/InventoryLogSubscriberTest.php](../in-other-worlds/tests/Feature/Listeners/Logging/InventoryLogSubscriberTest.php).
