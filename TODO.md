# In Other Shops — TODO

Package-level work, typically surfaced by consuming projects.

---

## Done

- **Cart REST API** — `CommerceServiceProvider` auto-registers `GET/DELETE /api/cart` and `POST /api/cart/items` + `PATCH/DELETE /api/cart/items/{item}` when `commerce.cart.api.enabled`. Controllers/Resources/Requests live under `src/Commerce/Cart/Http/`. Owner resolution uses `Auth::user()` then `session()->getId()`. Resources include `unit_price`, `line_total`, and a `subtotal`. Cart token resolution is via Laravel defaults; consumers with non-default auth swap `commerce.cart.api.middleware`.
- **Storefront availability** — `BrowsableResource` now exposes `in_stock` when the model implements `HasAvailability`.
- **Inventory `Restock` reason** — added to `StockMovementReason` enum (common user-facing wording for inbound supplier stock).
- **Taxonomy tag ordering** — `tags` table gains a `position` column; Filament `TagResource` gets drag-drop reorder to match the category UX.
- **Logging README** — clarified that Laravel 11+ event auto-discovery is the primary wiring pattern; `subscribe()` + `Event::subscribe()` is a fallback and causes double dispatch if combined with auto-discovery.

---

## Open — Foundation hardening

Prior to building checkout in the `in-other-worlds` consumer project, a critical-eye review (2026-04-15) surfaced correctness bugs and design gaps. These must land before any checkout work. Items are ordered so each phase unblocks the next.

### Phase A — Correctness bugs (blockers)

- [x] **A1. FlowChain transaction rollback** — fixed via internal `FlowChainRollbackSignal` exception. `run()` now delegates to `runInTransaction()` which throws the signal inside the `DB::transaction` closure on step failure, catches outside, returns the `FlowChainResult`. Regression test at `tests/Unit/FlowChain/FlowChainTransactionTest.php` covers: failed step rolls back earlier writes (the bug), successful chain persists, non-transactional chains leave earlier writes intact.
- [x] **A2. Stock reservation concurrency** — `AdjustStock` now acquires `SELECT ... FOR UPDATE` on the `stock_items` row before adjusting (with unique-violation fallback on first-time create). `ReleaseExpiredReservations` rewritten to process one movement at a time inside a per-movement transaction, selecting with `lockForUpdate` + `reason = Reserved` guard so concurrent workers no longer double-release. Regression coverage in `tests/Feature/Inventory/ReleaseExpiredReservationsTest.php` (6 tests): idempotent double-run, guard against already-released rows, non-expired untouched, null-TTL untouched, single `StockReleased` event per release.
- [x] **A3. Inventory ledger/reservation split** — rearchitected rather than patched. `stock_movements` is now truly append-only (`const UPDATED_AT = null`, `reserved_until` column removed). Reservation lifecycle moved to a new `stock_reservations` table (`status` ∈ Pending/Confirmed/Released, `reserve_movement_id` unique FK, nullable `release_movement_id`). `ConfirmReservation` transitions `Pending → Confirmed` with no new ledger entry (stock already decremented at reserve time); the original `-X Reserved` movement stays untouched. `ReleaseReservation` appends a compensating `+X Released` movement and transitions `Pending → Released`, leaving the reserve movement immutable. Concurrency guard is `status = Pending` + `lockForUpdate` on the reservation row. New events: `ReservationCreated` / `ReservationConfirmed` / `ReservationReleased`; `StockReleased` now carries `(reservation, releaseMovement)`. Dead `ReleaseStock` action removed. Test coverage: `ReserveStockTest` (4), `ConfirmReservationTest` (6), `ReleaseExpiredReservationsTest` (7). Package 20/20 green; consumer 87/87 green.
- [x] **A4. Voucher usage enforcement** — split read from write. The old `ApplyVoucher` (calc-only) is renamed to `CalculateVoucherDiscount` and stays the path used by `CalculateTotal` for cart-total displays — incrementing on every render would burn a use per page load. The new `ApplyVoucher` is the commit action: wraps in `DB::transaction`, acquires `SELECT ... FOR UPDATE` on the voucher row, re-validates, calls `incrementUsage()`, dispatches `VoucherApplied` after commit. Returns the locked `Voucher` so the order-creation action (E1) can snapshot it. The lock + re-validate inside a transaction means concurrent applies cannot exceed `max_uses` — the second waiter sees the incremented row and throws. When the outer transaction (order creation) rolls back, the increment rolls back too. New `VoucherFactory` ships in `src/Pricing/Database/Factories/`. Test coverage: `CalculateVoucherDiscountTest` (9), `ApplyVoucherTest` (8) — including outer-rollback test. 37/37 package tests green. Wiring into the order-commit transaction lands with E1.
- [x] **A5. `isPaid` returns true on partial payment** — `InteractsWithPayments::isPaid` used `succeeded.exists()`, so a €4 succeeded payment on a €10 order reported paid. Now: `totalPaid()` sums `amount - amount_refunded` across payments with status Succeeded or PartiallyRefunded; `isPaid()` returns `totalPaid() >= getPaymentTotalDue()`. `getPaymentTotalDue()` is a new contract method — the payable supplies its total (Order returns `$this->total`) because the Payment domain cannot infer the owing amount from payments alone. New `PaymentFactory` ships with the package; `TestPayable` stub + migration live in `tests/Stubs/` for isolated payment testing. Coverage: `IsPaidTest` (10 scenarios — unpaid/partial/exact/overshoot, multi-payment, refunded, partially-refunded, mixed). Package tests green.

### Phase B — Test infrastructure (enables A)

- [x] **B1. PHPUnit inside the package** — `phpunit.xml`, `tests/TestCase.php` (Orchestra Testbench), `composer test` runs it. MySQL test DB `in_other_shops_testing` with a dedicated `in_other_shops` user (production dialect parity). CLAUDE.md updated.
- [x] **B3. Testbench service provider** — `TestCase::getPackageProviders` registers all 13 domain providers. `defineEnvironment()` configures the MySQL connection.
- [ ] **B2. Factories ship in the package** — pattern established with `StockItemFactory` + `StockMovementFactory` in `src/Inventory/Database/Factories/`, models use `HasFactory` + `newFactory()` override resolving the model class via the domain registry. Remaining: sweep the other domains (Commerce, Pricing, Payment, Location, Media, Taxonomy, Translation) and backfill factories when their tests land or consumer factories need to move over.

### Phase C — API & extension-point fixes

- [x] **C1. Rename `*able` contracts → `Has*`** — `Cartable` → `HasCart`, `Orderable` → `HasOrders`, `Browsable` → `HasStorefrontPresence`, `Translatable` → `HasTranslations`. Trait `IsBrowsable` → `InteractsWithStorefrontPresence` for convention match. Consumer imports rewritten in the same pass.
- [x] **C2. `PaymentGatewayManager`** — `PaymentGateway` no longer has a single binding. `PaymentGatewayManager` is a singleton: drivers register via `extend(name, Closure)`. `InitiatePayment` takes gateway name as first argument; `HandlePaymentWebhook` takes name as first argument; `RefundPayment` resolves from `$payment->gateway`. Config shape is `payment.gateways.{name}`.
- [x] **C3. `InitiatePaymentResult` generalization** — DTO carries either `redirectUrl`, `clientSecret`, or neither (out-of-band). `PaymentSession` mirrors the shape.
- [x] **C4. Storefront hardcoded consumer FQCN** — `storefront.models` defaults to `[]`; consumer ships its own `config/storefront.php`. Added `config/storefront.php` in in-other-worlds.
- [x] **C5. Registry return type** — `Pricing::price()` and peers return `class-string<X>`. All `::class` gymnastics at call sites removed (package-wide sed). `$model::class` on stored class-strings replaced with plain `$model`. Did not add `query()`/`make()` helpers — `X::registry()::query()` and `new (X::registry())(...)` read fine; can add later if a call site wants them.
- [x] **C6. Cart price snapshot** — `cart_items` gets `unit_price` + `currency` columns. `AddToCart` writes them from `Cartable::getCartableUnitPrice($currency)`. Follow-up UX (staleness banner) is a consumer concern.
- [x] **C7. `gateway_reference` per-gateway uniqueness** — composite unique `(gateway, gateway_reference)` on `payments`. `HandlePaymentWebhook` queries by both fields.
- [x] **C8. `Shipment.currency`** — added column. `CalculateShippingCost` now takes `Currency` and returns `ShippingCost` DTO.
- [x] **C9. Tax / discount representation consistency** — percentage vouchers store basis points (1000 = 10%) and round with `round()`. `CalculateTax` required-arg only (no 2100 default). `CalculateTotal` requires `taxRate` as a named argument. Filament VoucherResource still accepts admin-friendly percent input and formats output accordingly.
- [x] **C10. `carts` unique index** — `(owner_type, owner_id)` unique. `ClaimCart` now handles merge into an existing owner cart; consumer's `ClaimGuestCart` is now a thin wrapper that just finds the guest cart by session token.
- [x] **C11. `Relation::requireMorphMap()`** — enabled globally in `CurrencyServiceProvider::boot()`.

### Phase D — Logging backfill (package ships subscribers)

Ruling (2026-04-15): logging is vital shop functionality; subscribers ship in the package, not per-consumer. `InventoryLogSubscriber` is the pattern.

- [ ] **D1. `CommerceLogSubscriber`** — `OrderCreated` / `OrderFailed` / `OrderStatusChanged` → `commerce` channel.
- [ ] **D2. `PaymentLogSubscriber`** — `PaymentSucceeded` / `PaymentFailed` / `PaymentRefunded` → `payment` channel (failures at `Error` level).
- [ ] **D3. `FlowChainLogSubscriber`** — `FlowChainStarted` / `FlowChainCompleted` / `FlowChainFailed` / `FlowChainStepFailed` → `flowchain` channel.
- [ ] **D4. `PricingLogSubscriber`** — `PriceCreated` / `PriceUpdated` / `PriceDeleted` + voucher apply → `commerce` channel (audit trail).
- [ ] **D5. Default log channels in `domain-log.php`** — `commerce`, `payment`, `flowchain`. Consumers override.

### Phase E — Checkout primitives

- [ ] **E1. `CreateOrder` action** — takes cart + customer + addresses + `PriceBreakdown`; creates `Order` + `OrderLine`s with snapshotted pricing; dispatches `OrderCreated`. Order-number generation strategy (configurable).
- [ ] **E2. `StockReservationFailed` event** — fires from `ReserveStock` when requested quantity exceeds available. Lets checkout handle oversell gracefully instead of bare exception.
- [ ] **E3. Webhook idempotency** — `webhook_events` table with `(gateway, event_id)` unique; `HandlePaymentWebhook` records+dedupes. `WebhookPayload` DTO gains `eventId`.
- [ ] **E4. Gateway webhook signature verification in contract** — add `verifyWebhookSignature(Request $request): void` (throws on mismatch) to `PaymentGateway`; split from `parseWebhook`.
- [ ] **E5. Guest cart expiry** — `expires_at` column on `carts`; prune command. Prevents session-token cart accumulation.

### Phase F — Stripe driver

Depends on C2 (manager), C3 (DTO), E3 (idempotency), E4 (signature).

- [ ] **F1. `composer.json`** — add `stripe/stripe-php` to `suggest`. No hard require.
- [ ] **F2. `StripePaymentGateway`** — implements `PaymentGateway` + `ManagesCustomers`. Uses Payment Intents. Returns `clientSecret` via `InitiatePaymentResult`.
- [ ] **F3. `StripeGatewayServiceProvider`** — registers via `PaymentGatewayManager::extend('stripe', …)` only when `class_exists(\Stripe\StripeClient::class) && config('payment.gateways.stripe.secret')`. Sets the optional-dep driver precedent.
- [ ] **F4. Config shape** — `payment.gateways.stripe.{secret, webhook_secret, …}`.

---

## Deferred / Watch

- 💭 **Split `OrderStatus`** into fulfillment status + derive payment status from `payments` — review called for this, but it's a clean follow-up, not a foundation fix. Revisit once checkout is live.
- 💭 **Extract Customer from Commerce** — speculative; no second consumer needs it. Revisit when/if a second consumer arrives.
- 💭 **Filament `suggest` split** — make Filament optional and split resources into a sub-package. Decision (2026-04-15): Filament is the correct backend tool; no headless consumer exists. Revisit only if one appears.
- 💭 **Option C FlowChain child-chain bridge** — ship Option B (trait) when the first sub-chain lands (payment inside checkout); upgrade to Option C (framework) only if a second bridge + debugging pain arises.
- 💭 **`withoutEvents()` on FlowChain** — no consumer needs it; consider removal.
- 💭 **FlowChain `runId` on events** — add for cross-event correlation when observability needs it.
- 💭 **Registry model swap consistency** — several actions (`ApplyVoucher`, `HandlePaymentWebhook`, `AddToCart`, `ResolveCart`) query concrete models instead of the registry. Fix in a sweep once C5 lands.
- 💭 **Order `tax_rate` snapshot** — if statutory rate changes, old orders re-render at new rate. Snapshot when VAT work starts.
- 💭 **Inventory schedule gated** — `InventoryServiceProvider` schedules `inventory:release-expired` every 5min unconditionally + registers a Livewire component without declaring the dep. Gate behind config + `class_exists` check.
