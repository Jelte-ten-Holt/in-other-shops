# In Other Shops — TODO

Package-level work, typically surfaced by consuming projects.

---

## Done

- **Storefront availability** — `BrowsableResource` now exposes `in_stock` when the model implements `HasAvailability`.
- **Inventory `Restock` reason** — added to `StockMovementReason` enum (common user-facing wording for inbound supplier stock).
- **Taxonomy tag ordering** — `tags` table gains a `position` column; Filament `TagResource` gets drag-drop reorder to match the category UX.
- **Logging README** — clarified that Laravel 11+ event auto-discovery is the primary wiring pattern; `subscribe()` + `Event::subscribe()` is a fallback and causes double dispatch if combined with auto-discovery.

## Open

_No open items. Surface new work here as consuming projects encounter it._
