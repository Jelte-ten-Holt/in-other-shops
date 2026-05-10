# In Other Shops — TODO

Package-level work, typically surfaced by consuming projects. Completed items have been removed — see git history.

---

## Open

_(none — see Deferred / Watch)_

## Recently shipped, awaiting release

- [ ] **`AuthenticateAgent` warning logs on swallowed exceptions** — both catch sites in `authenticateViaOauth` (`Auth::guard('api')` and `$guard->user()`) now emit `Log::warning` with exception class + message via a new `logAuthFailure()` helper. Distinct messages per site so operators can tell which leg failed. Test coverage added: regression tests for both catch paths plus a happy-path no-warning assertion. Remove from this TODO once tagged and the in-other-worlds composer.lock is bumped.
- [ ] **`OrderFailed` event deleted** — never dispatched, and reflection showed it shouldn't live in the package: order-failure semantics are tied to the consumer's checkout orchestration, not to the package's `CreateOrder` action. `FlowChainFailed` already covers the audit primitive at the right abstraction level (`flowName === 'checkout'` is the typed slice consumers can filter on). Removed: the event class, the `CommerceLogSubscriber` wiring + handler, and the test that dispatched it directly. Consumers that want a typed `OrderFailed` should ship it project-side with a payload that means something to their app (cart_id, customer_id, throwable).
- [ ] **`branch-alias` corrected to `0.15.x-dev`** — was stale at `0.10.x-dev`. Affects `dev-main` resolution in dependency graphs only.
- [ ] **Cut as v0.15.1** — additive logging + dead-code removal. No API change for current consumers (no one was dispatching `OrderFailed`).

---

## Deferred / Watch

- 💭 **Variants domain** — design at [`docs/variants-design.md`](docs/variants-design.md) (2026-05-09). Separate domain (not folded into Taxonomy), `Option` / `OptionValue` / `Variant` naming, polymorphic `HasVariants` ownership on the consumer side, per-variant stock and pricing, parent slug + optional `?variant=42` deep link, Variant-as-cartable. Build gated on a consumer use case — `in-other-worlds` is SKU-flat by design. Out of scope explicitly parked: configurable ("OR-slot") bundles, variants with different tax categories.
- 💭 **Extract Customer from Commerce** — speculative; no second consumer needs it. Revisit when/if a second consumer arrives.
- 💭 **Filament `suggest` split** — make Filament optional and split resources into a sub-package. Decision (2026-04-15): Filament is the correct backend tool; no headless consumer exists. Revisit only if one appears.
- 💭 **Option C FlowChain child-chain bridge** — ship Option B (trait) when the first sub-chain lands (payment inside checkout); upgrade to Option C (framework) only if a second bridge + debugging pain arises.
- 💭 **`withoutEvents()` on FlowChain** — no consumer needs it; consider removal.
- 💭 **FlowChain `runId` on events** — add for cross-event correlation when observability needs it.
- 💭 **Registry model swap consistency** — several actions (`ApplyVoucher`, `HandlePaymentWebhook`, `AddToCart`, `ResolveCart`) query concrete models instead of the registry. Fix in a sweep.
- 💭 **Thread `source` through stock-adjust call sites** — `config('inventory.sources')` lists `dashboard`, `checkout`, `import`, `agent`, but only the Agent tool actually passes `source`. `InventorySchema::saveStockAdjustment` (admin) should pass `source: 'dashboard'`; checkout/order flows should pass `source: 'checkout'`; import jobs `'import'`. Without this the audit trail can't tell these apart.
- 💭 **Test coverage** — package ships 74 tests but core actions like `CreateOrder`, `AddToCart`, `RemoveFromCart`, `UpdateCartItemQuantity`, `ClaimCart`, `InitiatePayment`, `HandlePaymentWebhook`, `RefundPayment`, `CalculateTotal`, `ResolvePrice` have no package-level tests.
