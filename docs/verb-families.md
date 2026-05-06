# Verb families

Per-domain naming convention for invokable Actions. The goal is predictability: once a reader has seen two or three actions in a domain, they should be able to guess the rest of the verbs without grep'ing. Same for an LLM caller of agent tools that wraps these actions, and for anyone navigating the admin.

Each domain picks a **primary family** and a small set of **permitted secondary verbs** (with conditions). Cross-cutting verbs have stable meanings package-wide and are listed in the glossary below — those reserved meanings override per-domain habits.

> **Status:** baseline drafted 2026-05-06. Renames pending listed at the bottom; nothing renamed yet.

---

## Cross-domain glossary (reserved meanings)

These verbs mean the same thing wherever they appear. Don't overload them within a domain; pick a different verb if your operation isn't one of these.

| Verb | Means | Side effects? |
|---|---|---|
| `Calculate` | Pure function over inputs. Returns a value. | No. |
| `Resolve` | Look up the most-applicable record given a context, with fallback. May return null. | No. |
| `Apply` | Compute and **record** the effect (consume usage, lock a state). | Yes — typically a write + event. |
| `Initiate` | Start of a longer-running flow (often async or external). | Yes — creates the flow record, hands off to gateway/queue. |
| `Process` | Handle an incoming event from the outside (webhook, queue payload). | Yes — interprets payload, dispatches to internal action. |
| `Retrieve` | Re-fetch existing external state (gateway session, third-party object). | No internal write; may hit external API. |
| `Confirm` | Move a pending state-machine entry into its committed state. | Yes — state transition + event. |
| `Release` | Revert a reservation/hold back to the available pool. | Yes — state transition + event. |
| `Reserve` | Move available state into a pending hold. | Yes — state transition + event. |
| `Adjust` | Apply a signed delta to a quantity (often the foundational primitive other state-machine verbs build on). | Yes. |
| `Attach` / `Detach` | Manage a polymorphic relationship between two records. | Yes — pivot row + event. |
| `Show` | Read a single record by identifier. | No. |
| `List` | Read many records, possibly paginated/filtered. | No. |
| `Ensure` | Guard / assertion. Returns void on success, throws on failure. Always private or test-helper-flavor; not a primary user action. | No (just throws). |
| `Store` | Persist content where the **bytes are the point** (uploads, audit log entries). Use instead of `Create` when "creating" understates "saving the artifact." | Yes. |

If your operation doesn't fit any of these, that's a signal to think harder before inventing a new verb — the family is small on purpose.

---

## Per-domain families

### Pricing

**Mixed-by-design.** Pricing manages records (CRUD) AND performs computation, both legitimately.

- **CRUD for record types**: `Create`, `Update`, `Delete` on `Price`, `PriceList`, `Voucher`.
- **Computation for read-side / pure functions**: `Resolve` (lookup-with-fallback), `Calculate` (pure), `Apply` (compute + record effect).
- Banned conflation: never use `Create` for a voucher application — that's `Apply` (records usage as a side effect). Never use `Calculate` for an action that writes — that breaks the glossary contract.

Current actions: `CreatePrice`, `UpdatePrice`, `DeletePrice`, `ResolvePrice`, `CalculateTax`, `CalculateTotal`, `CalculateVoucherDiscount`, `ApplyVoucher`. ✅ Compliant.

### Inventory

**Primary family: state machine.** `Adjust`, `Reserve`, `Confirm`, `Release` — clean.

- `Adjust` is the primitive every other verb builds on (the FOR UPDATE lock lives in `AdjustStock`).
- `Reserve` / `Confirm` / `Release` move reservations through their lifecycle.
- Permitted: `Release*` modifiers like `ReleaseExpiredReservations` for batch variants.
- Banned: `Decrement`, `Increment`, `Subtract`, `Add` — they describe the math, not the domain operation. Use `Adjust` with a signed delta.

Current actions: `AdjustStock`, `ReserveStock`, `ConfirmReservation`, `ReleaseReservation`, `ReleaseExpiredReservations`. ✅ Compliant.

### Taxonomy

**Primary family: relation.** `Attach`, `Detach`. Clean.

- Future verbs in this family if needed: `Move` (re-parent), `Reorder` (sibling order).
- Banned: `Add`/`Remove` — those are the cart-style family. Tags and categories aren't items in a list, they're polymorphic relationships.

Current actions: `AttachCategory`, `DetachCategory`, `AttachTag`, `DetachTag`. ✅ Compliant.

### Storefront

**Primary family: inquiry.** `Show`, `List`. Read-only domain.

- Future: `Browse` is acceptable when filtering+pagination is the point (a `BrowsableResource` already exists, so `Browse*` would mirror it).
- Banned: `Get`, `Find`, `Fetch` — pick `Show` (single) or `List` (many) and stick to it. The Agent tool layer also follows `show_*` / `browse_*` / `list_*` per `agent-tool-conventions.md`.

Current actions: `ShowBrowsable`, `ListBrowsables`, `ListCategoryBrowsables`. ✅ Compliant.

### Tax

**Primary family: computation.** `Resolve`. Single verb today; if Tax grows, stay in the computation family (`Calculate`, `Resolve`, `Apply`).

Current: `ResolveTaxRate`. ✅ Compliant.

### Payment

**Primary family: transaction lifecycle.** `Initiate`, `Refund`, `Retrieve`. Plus `Process` for incoming webhook events.

- `Initiate` — start of payment flow (creates Payment record + gateway session).
- `Refund` — terminal state action.
- `Retrieve` — re-fetch session for re-rendering.
- `Process` — handle an inbound webhook event.
- Banned: `Capture` is reserved for the auth-then-capture pattern if/when we add it; do not use it loosely. `Confirm`/`Release` belong to Inventory (state-machine semantics differ — payments use lifecycle verbs).

Current actions: `InitiatePayment`, `RefundPayment`, `RetrievePaymentSession`, `HandlePaymentWebhook`.

⚠ **Wobble:** `HandlePaymentWebhook` uses `Handle`, which isn't in the glossary. Per the reserved meaning of `Process` ("handle an incoming event"), this should rename. See *Renames pending*.

### Media

**Primary family: persistence.** `Store`, `Delete`.

- `Store` (not `Create`) signals "the file IS the operation." Defensible because uploads aren't a record-creation in the CRUD sense; the bytes are the point.
- Future: `UpdateMedia` is permitted for metadata edits (alt text, collection rename) — those ARE CRUD-on-record operations. `Store` stays for content writes.
- Banned: `Upload` (use `Store`); `Save` (too generic).

Current actions: `StoreMedia`, `DeleteMedia`. ✅ Compliant.

### Shipping

**Mixed-by-design** — three different operation kinds, each in its appropriate family.

- `Calculate` — pure pricing function: `CalculateShippingCost`.
- `Create` — record creation: `CreateShipmentForOrder`.
- `List` — inquiry: `ListAvailableShippingMethods`.

Each verb is correct for what it does. The mix isn't drift; it's three different operation kinds.

Current actions: `CalculateShippingCost`, `CreateShipmentForOrder`, `ListAvailableShippingMethods`. ✅ Compliant.

### Commerce/Cart

**Primary family: cart-style mutation.** `Add`, `Remove`, `Clear`, `Update`, `Claim`.

- `AddToCart`, `RemoveFromCart`, `ClearCart`, `UpdateCartItemQuantity` — collection mutations.
- `ClaimCart` — guest-to-authenticated transition; cart-style fits because the verb's subject is the cart.
- Permitted: `Resolve` for lookup-with-fallback (`ResolveCart` finds the active cart for a session/owner pair).
- Permitted: `Ensure` for private guards (`EnsureCartableInStock`) — never user-facing, always asserts and throws.

Current actions: `AddToCart`, `RemoveFromCart`, `ClearCart`, `UpdateCartItemQuantity`, `ClaimCart`, `ResolveCart`, `EnsureCartableInStock`. ✅ Compliant.

### Commerce/Customer

**Primary family: CRUD.** `Create`, `Update`. Clean.

Current actions: `CreateCustomer`, `UpdateCustomer`. ✅ Compliant.

### Commerce/Order

**Primary family: CRUD.** `Create`, `Update`.

- `CreateOrder` — assembles an Order from a Cart + price breakdown.
- `UpdateOrderStatus` — sets the status column with transition validation.

Open question (not blocking): should `UpdateOrderStatus` become `TransitionOrderStatus` to telegraph the state-machine semantics? Arguments both ways:
- *For:* status moves are state-machine, not arbitrary updates. Renaming aligns with Inventory's clarity.
- *Against:* the verb `Update` accurately describes what it does at the database level; the state-machine logic is a guard, not the primary act.

Defaulting to **leave as-is** — the validation is still there, the verb still says what it does, and renaming touches every callsite. Revisit if Order grows more state-machine actions (e.g. `CancelOrder`, `RefundOrder`).

Current actions: `CreateOrder`, `UpdateOrderStatus`. ✅ Compliant (modulo open question).

---

## Renames pending

Single rename to ratify before this doc goes from draft to enforced.

| From | To | Why |
|---|---|---|
| `Payment\Actions\HandlePaymentWebhook` | `Payment\Actions\ProcessPaymentWebhook` | `Handle` is not in the glossary; `Process` is the reserved verb for webhook/event ingestion. Rename clarifies it's the inbound-event processor, not generic "do something with." |

Touchpoints for the rename:
- File path: `src/Payment/Actions/HandlePaymentWebhook.php` → `src/Payment/Actions/ProcessPaymentWebhook.php`
- Class name + namespace import sites in package
- Service provider / route registrations that reference the action class
- Consumer (in-other-worlds): grep `HandlePaymentWebhook`

---

## How to use this doc

When adding a new action:

1. Locate the domain it belongs to.
2. Pick a verb from the domain's primary family OR a permitted secondary verb. Don't invent.
3. If your operation doesn't fit any verb here, that's a signal to:
   - rethink whether it's actually one action or two, OR
   - propose a new reserved verb (PR this file first, action second).
4. Cross-domain reserved verbs (glossary) override per-domain habits — `Calculate` always means pure, `Apply` always means pure + records, etc.

When reviewing a PR that adds an action: name compliance is a real review item. A drift-named action will become next year's audit finding.
