# In Other Shops — TODO

Package-level work, typically surfaced by consuming projects. Completed items have been removed — see git history.

---

## Open

_(none — see Deferred / Watch)_

## Recently shipped, awaiting release

- **bianka AUDIT-2026-07-04 package fixes** (branch `fix/audit-bianka-2026-07-04`, 2026-07-04) — four decision-free fixes surfaced by the bianka consumer audit:
  - **SCALE-4** — `Media\Concerns\InteractsWithMedia`: `coverImage()`/`firstMedia()` resolve from the eager-loaded `media` relation when present (mirrors `hasVariants()` / the ResolvePrice SCALE-2 fix); kills 1–2 wasted queries per catalog card + one per option-value swatch. Read-path only.
  - **BUG-3 (package part)** — `Commerce\Cart\Concerns\InteractsWithCart::getCartableLabel()` default is null-safe: name → slug → `"{morph alias} #{key}"`, still `string`. A name-less cartable (admin-created bundle with zero translations) no longer TypeErrors every cart response. Consumer half (finish BundleForm, gate storefront visibility) stays in bianka.
  - **BUG-7** — `FindOrCreateCartItemStep` create path uses `createOrFirst`: the two-tab double-add unique violation on `(cart_id, cartable_type, cartable_id)` converts to the increment path (savepoint-wrapped, so the FlowChain transaction survives on Postgres too) instead of escaping as a raw `QueryException` 500. Payload contract unchanged.
  - **DRY-1** — new `Pricing::defaultPriceList(): ?PriceList` + `forgetDefaultPriceList()` backed by a `scoped()`-bound `Pricing\Support\DefaultPriceListResolver` (once per request/scope, null memoized, Octane/queue-safe). On release-and-bump, delete the 11 hand-rolled copies across both consumers (bianka ×6, IOW ×5 incl. `Support/DefaultPriceList.php`).

_(previous: v0.29.0 tagged 2026-06-02, bianka bumped.)_

### Shipped history

- **v0.29.0** (2026-06-02) — `Variants\OptionValue` adopts `HasMedia`: optional single swatch image per value (`swatch` collection) for the storefront variant picker; per-value `FileUpload` in `OptionResource`. No migration. bianka bumped.
- **v0.28.0** (2026-06-02) — **Variants domain** (`Option`/`OptionValue`/`Variant`, `HasVariants`, cart-able `Variant`, `Create`/`Generate`/`CreateDefault`/`DeleteVariant`, `OptionResource` + `VariantsSchema`, new verb `Generate`). Design: [`docs/variants-design.md`](docs/variants-design.md). bianka bumped + catalog admin built.
  - ⚠️ **Cross-consumer behavior change still to verify on in-other-worlds bump:** `Commerce\Cart\Concerns\InteractsWithCart` gained a `deleting` guard blocking deletion of any cart-able referenced by a live cart (config `commerce.cart.guard_cartable_deletion`, default true). in-other-worlds suite unaffected (no test deletes a cartable); admin Product/Bundle deletion will block when a live cart references it. Follow-up tracked in the in-other-worlds TODO. in-other-worlds is **not yet bumped** to v0.28+.
  - Deferred (consumer-side, bianka storefront/cart build): generate-combinations + price-cascade live on bianka's Product Edit page; storefront variant picker + "from $X" + cart-as-variant; Storefront-API variant surfacing.

_(v0.22.3 tagged 2026-05-27 — the v0.22 series shipped the FlowChain publish-and-modify mechanism + AddToCartChain.)_

---

## Deferred / Watch

- 💭 **Convert `InitiatePayment` to a published FlowChain** — `InitiatePayment`'s 5 internal steps (gateway resolve → payment record create → customer ID resolve → session create → gateway-reference write-back) would let consumers insert fraud check pre-session, analytics ping post-session, custom customer-resolution branch, etc. without forking the action. **Blocked on sub-chain machinery** (`->chain()` builder method + `FlowChainBridge` interface, child step results nest into parent's `FlowChainResult`) — see FlowChain README §Child Chains for Option B/C design. AddToCartChain (v0.22.0) is the first published chain and proves the basic `PublishableFlowChain` path works; InitiatePayment specifically needs the nested-chain primitive that's still unbuilt. Narrower than "convert all of Payment": `RefundPayment` shouldn't be split (lockForUpdate + intentional gateway-call-before-DB-write ordering doesn't fragment cleanly), `ProcessPaymentWebhook` doesn't fit either (early-return-null successful no-ops, no parent chain to nest into). Trigger to un-defer: a real consumer extension request, OR new payment surface area (subscription renewals, 3DS retry, manual charges) that adds steps the current single action can't cleanly accommodate.
- 💭 **Extract Customer from Commerce** — speculative; no second consumer needs it. Revisit when/if a second consumer arrives.
- 💭 **Filament `suggest` split** — make Filament optional and split resources into a sub-package. Decision (2026-04-15): Filament is the correct backend tool; no headless consumer exists. Revisit only if one appears.
- 💭 **Filament admin i18n (translate the dashboard chrome)** — the package's Filament Resources/Schemas ship hardcoded English labels, help text, table headers, and action labels. `bianka-shop-one` runs a Spanish-first *storefront* but its operator works the dashboard in English, which is accepted at launch. When a consumer wants a localized admin, route the package's Filament surface through Laravel translation files (`lang/`) so consumers can drop in `lang/{locale}` overrides. Surfaced by bianka-shop-one 2026-06-01. **Explicitly not a launch item** — do it when a consumer actually needs a non-English admin.
- 💭 **`withoutEvents()` on FlowChain** — no consumer needs it; consider removal.
- 💭 **FlowChain `runId` on events** — add for cross-event correlation when observability needs it.
- 💭 **Registry model swap consistency** — several actions (`ApplyVoucher`, `HandlePaymentWebhook`, `AddToCart`, `ResolveCart`) query concrete models instead of the registry. Fix in a sweep.
- 💭 **`AttachTag` is not idempotent** — `$model->tags()->attach($tag)` creates a duplicate pivot row if the same tag is attached twice. Surfaced 2026-05-18 by the project-side `Featured` `ToggleColumn` flow in `in-other-worlds` (which guards against this at the call site by reading current state before toggling, but the action itself remains a footgun for any caller that doesn't). Fix shape: switch to `syncWithoutDetaching($tag->id)` so re-attach is a no-op at the DB layer; suppress the `TagAttached` event when no row was actually created (the call returns `['attached' => [], 'detached' => [], 'updated' => []]` when the row already existed). Equivalent shape for `AttachCategory` if it has the same problem. Add a test that calls the action twice and asserts a single pivot row + a single event dispatch.
- 💭 **Test coverage** — suite at 477 tests after the 2026-05-11 sweep. Added `CreateOrderTest`, `InitiatePaymentTest`, `CalculateTotalTest` for missing-action coverage; new `ShippedDefaultConfigTest` per domain (Tax, Shipping, Logging) closes the "every test overrides config" gap. All audit-surfaced High + Medium half-tests fixed (try/catch with no-side-effect assertions, distinguishable values, narrowed scopes). Outstanding:
  - **Low — Filament internals tested as contract** — `OrderResourceTotalFieldTest` pins `isDisabled()` / `isDehydrated()` on Filament components rather than the "admin cannot edit total" contract. Acceptable as a regression anchor for the specific audit fix; revisit if a Livewire-stack-booted test layer lands.
  - **Concurrency probes are absent suite-wide** — `lockForUpdate` paths in `ApplyVoucher`, `AdjustStock`, `ReleaseReservation`, `ConfirmReservation`, `RefundPayment` are documented but untested. Real contention testing needs multi-process + a real RDBMS (not SQLite in-memory). The doc acknowledges this; flagged for the day a real contention probe lands.
