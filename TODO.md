# In Other Shops — TODO

Package-level work, typically surfaced by consuming projects. Completed items have been removed — see git history.

---

## Open

_(none — see Deferred / Watch)_

## Recently shipped, awaiting release

_(none — v0.22.3 tagged 2026-05-27, consumer bumped. The v0.22 series shipped the FlowChain publish-and-modify mechanism (v0.22.0) + metadata passthrough on AddToCart (v0.22.1) + flowchain:publish rename handling (v0.22.2) + non-final AddToCartChain (v0.22.3). AddToCartChain is the first published chain — in-other-worlds Layer 1 attribution validates it.)_

---

## Deferred / Watch

- 💭 **Convert `InitiatePayment` to a published FlowChain** — `InitiatePayment`'s 5 internal steps (gateway resolve → payment record create → customer ID resolve → session create → gateway-reference write-back) would let consumers insert fraud check pre-session, analytics ping post-session, custom customer-resolution branch, etc. without forking the action. **Blocked on sub-chain machinery** (`->chain()` builder method + `FlowChainBridge` interface, child step results nest into parent's `FlowChainResult`) — see FlowChain README §Child Chains for Option B/C design. AddToCartChain (v0.22.0) is the first published chain and proves the basic `PublishableFlowChain` path works; InitiatePayment specifically needs the nested-chain primitive that's still unbuilt. Narrower than "convert all of Payment": `RefundPayment` shouldn't be split (lockForUpdate + intentional gateway-call-before-DB-write ordering doesn't fragment cleanly), `ProcessPaymentWebhook` doesn't fit either (early-return-null successful no-ops, no parent chain to nest into). Trigger to un-defer: a real consumer extension request, OR new payment surface area (subscription renewals, 3DS retry, manual charges) that adds steps the current single action can't cleanly accommodate.
- 💭 **Variants domain** — design at [`docs/variants-design.md`](docs/variants-design.md) (2026-05-09). Separate domain (not folded into Taxonomy), `Option` / `OptionValue` / `Variant` naming, polymorphic `HasVariants` ownership on the consumer side, per-variant stock and pricing, parent slug + optional `?variant=42` deep link, Variant-as-cartable. Build gated on a consumer use case — `in-other-worlds` is SKU-flat by design. Out of scope explicitly parked: configurable ("OR-slot") bundles, variants with different tax categories.
- 💭 **Extract Customer from Commerce** — speculative; no second consumer needs it. Revisit when/if a second consumer arrives.
- 💭 **Filament `suggest` split** — make Filament optional and split resources into a sub-package. Decision (2026-04-15): Filament is the correct backend tool; no headless consumer exists. Revisit only if one appears.
- 💭 **`withoutEvents()` on FlowChain** — no consumer needs it; consider removal.
- 💭 **FlowChain `runId` on events** — add for cross-event correlation when observability needs it.
- 💭 **Registry model swap consistency** — several actions (`ApplyVoucher`, `HandlePaymentWebhook`, `AddToCart`, `ResolveCart`) query concrete models instead of the registry. Fix in a sweep.
- 💭 **`AttachTag` is not idempotent** — `$model->tags()->attach($tag)` creates a duplicate pivot row if the same tag is attached twice. Surfaced 2026-05-18 by the project-side `Featured` `ToggleColumn` flow in `in-other-worlds` (which guards against this at the call site by reading current state before toggling, but the action itself remains a footgun for any caller that doesn't). Fix shape: switch to `syncWithoutDetaching($tag->id)` so re-attach is a no-op at the DB layer; suppress the `TagAttached` event when no row was actually created (the call returns `['attached' => [], 'detached' => [], 'updated' => []]` when the row already existed). Equivalent shape for `AttachCategory` if it has the same problem. Add a test that calls the action twice and asserts a single pivot row + a single event dispatch.
- 💭 **Test coverage** — suite at 477 tests after the 2026-05-11 sweep. Added `CreateOrderTest`, `InitiatePaymentTest`, `CalculateTotalTest` for missing-action coverage; new `ShippedDefaultConfigTest` per domain (Tax, Shipping, Logging) closes the "every test overrides config" gap. All audit-surfaced High + Medium half-tests fixed (try/catch with no-side-effect assertions, distinguishable values, narrowed scopes). Outstanding:
  - **Low — Filament internals tested as contract** — `OrderResourceTotalFieldTest` pins `isDisabled()` / `isDehydrated()` on Filament components rather than the "admin cannot edit total" contract. Acceptable as a regression anchor for the specific audit fix; revisit if a Livewire-stack-booted test layer lands.
  - **Concurrency probes are absent suite-wide** — `lockForUpdate` paths in `ApplyVoucher`, `AdjustStock`, `ReleaseReservation`, `ConfirmReservation`, `RefundPayment` are documented but untested. Real contention testing needs multi-process + a real RDBMS (not SQLite in-memory). The doc acknowledges this; flagged for the day a real contention probe lands.
