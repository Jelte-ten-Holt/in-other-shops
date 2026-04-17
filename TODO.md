# In Other Shops — TODO

Package-level work, typically surfaced by consuming projects. Completed items have been removed — see git history.

---

## Deferred / Watch

- 💭 **Split `OrderStatus`** into fulfillment status + derive payment status from `payments` — review called for this, but it's a clean follow-up, not a foundation fix. Revisit once checkout is live.
- 💭 **Extract Customer from Commerce** — speculative; no second consumer needs it. Revisit when/if a second consumer arrives.
- 💭 **Filament `suggest` split** — make Filament optional and split resources into a sub-package. Decision (2026-04-15): Filament is the correct backend tool; no headless consumer exists. Revisit only if one appears.
- 💭 **Option C FlowChain child-chain bridge** — ship Option B (trait) when the first sub-chain lands (payment inside checkout); upgrade to Option C (framework) only if a second bridge + debugging pain arises.
- 💭 **`withoutEvents()` on FlowChain** — no consumer needs it; consider removal.
- 💭 **FlowChain `runId` on events** — add for cross-event correlation when observability needs it.
- 💭 **Registry model swap consistency** — several actions (`ApplyVoucher`, `HandlePaymentWebhook`, `AddToCart`, `ResolveCart`) query concrete models instead of the registry. Fix in a sweep.
- 💭 **Order `tax_rate` snapshot** — if statutory rate changes, old orders re-render at new rate. Snapshot when VAT work starts.
- 💭 **`OrderFailed` event never dispatched** — the event is defined and subscribed in `CommerceLogSubscriber`, but nothing in the package dispatches it. `CreateOrder` doesn't fire it on exception — the exception just propagates. Consumer checkout FlowChain steps would need to dispatch it manually. Ship a helper or document the pattern.
- 💭 **Test coverage** — package ships 74 tests but core actions like `CreateOrder`, `AddToCart`, `RemoveFromCart`, `UpdateCartItemQuantity`, `ClaimCart`, `InitiatePayment`, `HandlePaymentWebhook`, `RefundPayment`, `CalculateTotal`, `ResolvePrice` have no package-level tests.
