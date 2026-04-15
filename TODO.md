# In Other Shops — TODO

Package-level work, typically surfaced by consuming projects.

---

## Done

- **Cart REST API** — `CommerceServiceProvider` auto-registers `GET/DELETE /api/cart` and `POST /api/cart/items` + `PATCH/DELETE /api/cart/items/{item}` when `commerce.cart.api.enabled`. Controllers/Resources/Requests live under `src/Commerce/Cart/Http/`. Owner resolution uses `Auth::user()` then `session()->getId()`. Resources include `unit_price`, `line_total`, and a `subtotal`. Cart token resolution is via Laravel defaults; consumers with non-default auth swap `commerce.cart.api.middleware`.
- **Storefront availability** — `BrowsableResource` now exposes `in_stock` when the model implements `HasAvailability`.
- **Inventory `Restock` reason** — added to `StockMovementReason` enum (common user-facing wording for inbound supplier stock).
- **Taxonomy tag ordering** — `tags` table gains a `position` column; Filament `TagResource` gets drag-drop reorder to match the category UX.
- **Logging README** — clarified that Laravel 11+ event auto-discovery is the primary wiring pattern; `subscribe()` + `Event::subscribe()` is a fallback and causes double dispatch if combined with auto-discovery.

## Open

_No open items. Surface new work here as consuming projects encounter it._
