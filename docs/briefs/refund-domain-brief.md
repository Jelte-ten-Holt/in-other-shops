# Brief — Refund / money-back domain (in-other-shops + in-other-worlds)

> **Status: BUILT ✅ (2026-06-04)** — all phases implemented + tested on branch `feat/refund-domain`
> (in-other-shops), full suite green (738). **Not released / not pushed** — awaiting go-ahead to cut the
> package release + bump the consumer. Commits: 87b93e4 (Wave 1), abb624b (Phase 0 + ReverseTax),
> da75ffe (Phase 1), 1c92e7f (Phase 2 core), db35af4 (Phase 3 webhook), f22ea57 (Phase 2d Filament),
> de81488 (Phase 4 indicator/guard).
>
> **Key build refinements vs. the v2 plan below:** (1) `RefundPayment` (Payment) can't depend on Commerce,
> so the orchestration is a **Commerce `RefundOrder`** action calling the thin `RefundPayment`; (2) the
> single Refund-row creator is **Commerce `RecordRefund`**, shared by `RefundOrder` (admin) and a new
> `ReconcileRefundFromWebhook` listener on `PaymentRefunded` (webhook) — so `PaymentRefunded` is now
> dispatched only by the webhook, and the audit event is Commerce's **`RefundRecorded`** (not an enriched
> `PaymentRefunded`); (3) the two Filament actions live on the **Commerce `OrderResource`** EditOrder page,
> not the Payment relation manager (dependency direction); (4) `RefundPayment` keeps the gateway call in
> its locked txn (double-click guard) but the Refund row + restock are now post-record, out of that txn.
>
> **Status: BUILD-READY (rebrief v2, 2026-06-04)** — after discuss pass 1 + adversarial critique pass 1.
> Origin: silent-correctness audit F27–F34 + round-2 Pass 6. The refund path is **half-built and
> admin-triggerable**: the payment row reflects a refund but nothing downstream does — no restock, no
> tax reversal, the Stripe refund webhook silently no-ops, reason/actor dropped. This brief finishes the
> domain. Supersedes the v1 design (the critique rewrote the tax math and the restock binding — see §9).

---

## 1. What's there vs. what silently isn't

**Present (and good):** `RefundPayment` action (lock + cap-revalidate + gateway-before-write — closes the
admin double-click race); `PaymentRefunded` event; webhook idempotency ledger (`WebhookEvent`, keyed
`gateway + event_id`) + amount/currency guard; `PaymentStatus::Refunded`/`PartiallyRefunded`;
`SyncInventoryOnOrderStatusChange` (restocks on `* → Cancelled`, docblock anticipates refund flows).

**Silently absent / broken:** F27 (charge.* read as PaymentIntent → webhook no-ops), F28 (no dispatch arm
+ no restock/transition), F29 (no order refund state — *resolved by not needing one, D6*), F30/F31
(webhook never writes `amount_refunded`), F32 (reason dropped), F33 (no tax reversal). Plus, surfaced by
the critique: `PaymentGateway::refund()` returns `void` so the gateway refund id is never captured.

---

## 2. Periphery map

- **`RefundPayment`** (admin, two Filament actions — see §4) → gateway refund, Payment update, `Refund`
  row, `PaymentRefunded`.
- **`ProcessPaymentWebhook`** (Stripe `charge.refunded`/`charge.refund.updated`) → second entry point;
  idempotent on `event_id` (redelivery) **and** `gateway_refund_id` (admin↔webhook convergence).
- **`SyncInventoryOnOrderStatusChange`** (package, auto-registered) — `OrderStatusChanged` → inventory.
  The "Refund & cancel" action reuses it via `UpdateOrderStatus(Cancelled)`.
- **`Payment::payable()`** morphTo → `Order`. **`Order::taxSummary()`** → per-bracket
  `TaxBreakdownLine[]` (source of truth for tax reversal). **`Order::reservations`** via the morph
  reference (source for the restock picker).
- **Audit:** a refund log subscriber (reason/actor/gateway_refund_id) — new, mirrors Purchasing.
- **Consumer (in-other-worlds):** no new listener; only a "Refunded" badge on its `OrderResource` and
  (optionally) a shipment-creation guard.

**External-surface changes periphery.md must record:** `PaymentGateway::refund()` return type; new
`WebhookPayload` fields; new `RefundPayment` signature / `RefundPaymentRequest` DTO; enriched
`PaymentRefunded` payload; morph alias `'refund'`; `Order::refunds()`/`isRefunded()`/`refundedTotal()`.

---

## 3. Invariants

1. A refund is matched to the right Stripe object (intent id read from the **Charge** for `charge.*`).
2. The same refund via admin and via webhook **converges to one `Refund` row and one `amount_refunded`**,
   anchored on `gateway_refund_id` (unique per `(gateway, gateway_refund_id)`). A missing id fails loud.
3. `Payment.amount_refunded` is the per-payment money-truth, written **monotonically** (`max(current,
   gateway cumulative)`) under `lockForUpdate`; `status` is **recomputed from amounts**
   (`amount_refunded >= amount ⇒ Refunded`, `0 < amount_refunded < amount ⇒ PartiallyRefunded`), never
   from the webhook event type. `sum(order.refunds.amount)` is the order rollup; they reconcile via
   `Refund.payment_id`.
4. The gateway refund call happens **outside** any DB transaction that could roll back; on success a short
   txn writes `amount_refunded` + the `Refund` row. Restock and order-cancel happen **after commit** — a
   rollback can never leave money-refunded-with-no-record.
5. Restock is an explicit operator choice over the order's **Pending-or-Confirmed reservations**;
   restocking releases the chosen reservations through the ledger (idempotent with the cancel path and the
   timeout cron). The action **asserts released-count == picked-count** — a pick that releases nothing is
   surfaced, not silently passed.
6. Tax reversal: each `Refund` stores the **delta** per bracket between the target cumulative reversed tax
   (largest-remainder split of `origBracketTax × amount_refunded / origAmount`, weighted by the **original
   `tax_summary` bracket tax**) and what prior refunds already reversed. At full refund, `Σ Refund.tax per
   bracket == order tax per bracket`, asserted at the boundary.
7. Every `Refund` carries `amount`, `reason`, and an `actor` that is **recorded, never null** (admin user
   for admin refunds; a `stripe` sentinel for webhook/dashboard refunds).
8. Refund never exceeds captured amount (kept from `RefundPayment`); money is integer cents.
9. `PaymentRefunded` is dispatched **once per `gateway_refund_id`** (first record), so the webhook echo of
   an admin refund doesn't double-fire customer-facing listeners.

---

## 4. Architecture

The order status is **not** touched by a refund except in the explicit "Refund & cancel" action. A
`Refund` row (in **Commerce/Order**, attached to the order) is the record of money returned; restock is an
explicit operator pick that releases chosen reservations. The whole flow is package-side.

```
 Admin: two Filament actions on the Payments relation
   • "Refund & cancel order"  → full refund + UpdateOrderStatus(Cancelled) → SyncInventory blanket-release
   • "Partial refund"         → amount + reason + reservation picker (release chosen) ; order stays put
 Stripe charge.refunded/​refund.updated → ProcessPaymentWebhook (no operator → no restock, no cancel)

            both paths ──►  gateway refund (returns re_…)         [OUTSIDE the write txn]
                                   │
                    short txn:  amount_refunded = max(cur, cumulative)  +  Refund row (upsert on re_…)
                                   │
              ┌────────────────────┼─────────────────────────┬───────────────────────┐
              ▼                    ▼                          ▼                       ▼
     status recomputed      [after commit] restock      PaymentRefunded         RefundRecorded
     from amounts            (admin pick / cancel-       (carries Refund;        → RefundLogSubscriber
                             blanket / webhook none)      once per re_…)          (amount, reason, actor)

 Derived (no order-status case): Order::isRefunded()/refundedTotal() from order->refunds
   → "Refunded" badge + soft warn-guard on shipment creation (full-refund-without-cancel + webhook refunds)
```

**Package pieces:**

- **`Refund` model — `src/Commerce/Order/`** (NOT Payment: a `Refund` in Payment with `order_id` would
  create a forbidden Payment→Commerce cycle). Columns: `order_id`, `payment_id`, `gateway`,
  `gateway_refund_id` (unique with `gateway`), `amount`, `tax_summary` (reversed, per-bracket, this
  refund's delta), `reason`, `actor_type`/`actor_id`/`actor_label`/`actor_source`, timestamps. Register
  `Commerce::refund()`, morph alias `'refund'`, `Order::refunds(): HasMany`,
  `Order::refundedTotal()`/`isRefunded()`. Ships with a factory (`HasFactory`). **No `restocked_lines`
  column** — the StockMovement ledger already records releases by order reference; query it.

- **`PaymentGateway::refund()` contract** → returns the gateway refund id (`string`). Implementors to
  update **in the same window**: `StripePaymentGateway` (return `$refund->id`; the dead `use Stripe\Refund`
  is the hint it was always intended), `FakePaymentGateway` (synthetic `re_…` + record it in
  `recordedRefunds()`), and the anon implementor in `tests/Feature/Payment/InitiatePaymentTest.php`.

- **`RefundPayment`** takes a **`RefundPaymentRequest` DTO** (`payment`, `amount`, `reason`, `actor`
  (a `RefundActor` VO: id + label + `source: admin|webhook|stripe`), `restockReservationIds`, `cancelOrder`).
  Flow: lock + cap-validate (kept) → **gateway refund outside the write txn** → short txn { monotonic
  `amount_refunded`, recompute status, compute per-bracket reversed-tax delta from `Order::taxSummary()`,
  insert `Refund` (upsert on `gateway_refund_id`) } → **after commit**: if `cancelOrder` →
  `UpdateOrderStatus(order, Cancelled)`; else → `ReleaseReservation` on each `restockReservationIds`
  (asserting released == picked). Dispatch `PaymentRefunded` + `RefundRecorded` once per `re_…`.

- **`StripePaymentGateway::parseWebhook`** branches on `event.type` **before reading variant fields**: for
  `charge.*`, `gatewayReference = charge.payment_intent`, `amount = charge.amount` (so `guardAmountMatches`
  still validates against `payment.amount`), and the new `amountRefunded = charge.amount_refunded`
  (cumulative) + `gatewayRefundId = <latest refund id>`. `WebhookPayload` gains `?int $amountRefunded` +
  `?string $gatewayRefundId`. `FakePaymentGateway::simulateWebhook` gains overrides for both (else the
  dashboard-refund test can't be forged). Drop the `charge.* → status` arms of `mapStatus`; status is
  recomputed from amounts downstream.

- **`ProcessPaymentWebhook`** for refund events: `lockForUpdate` the Payment, set `amount_refunded =
  max(current, payload.amountRefunded)`, recompute status, upsert the `Refund` row on `gateway_refund_id`
  (empty restock, `actor.source = stripe`), dispatch `PaymentRefunded`/`RefundRecorded` only if this is the
  first record of that `re_…`.

- **Audit:** enrich `PaymentRefunded` to carry the `Refund` (so a subscriber can log reason/actor), or add
  a dedicated `RefundRecorded` event + a `RefundLogSubscriber` (Commerce-side, mirrors Purchasing's
  model+event+subscriber). Invariant #7 is otherwise unbuildable — the current event carries only Payment.

- **Derived indicator + guard:** `Order::isRefunded()`/`refundedTotal()` over `order->refunds`; a
  "Refunded" badge in the admin order/shipment views; a **soft warn** (not block, per
  `feedback_warn_dont_force`) on shipment creation for a fully-refunded, un-cancelled order — closes the
  residual F28 risk left by "full refund without cancel" and every webhook/dashboard refund.

**Consumer (in-other-worlds):** no `HandlePaymentRefunded`. Only the "Refunded" badge on `OrderResource`
(extends the package resource) and any consumer-side shipment guard. Version bump after the package release.

---

## 5. Decisions (all resolved)

- **D1 — Two explicit Filament actions** (not one reactive modal): **"Refund & cancel order"** (full refund
  → `Cancelled` → existing blanket restock) and **"Partial refund"** (amount + reason + reservation picker).
  Sidesteps reactive-schema quirks (no precedent in the package) and maps 1:1 to the two restock modes.
- **D1a — Restock picks reservations directly** (product × qty × reservation id over the order's
  Pending-or-Confirmed reservations) — reservations reference the Order, not the OrderLine, so there's no
  `order_line_id` to bind a "line picker" to. No schema change. Whole-reservation release only.
- **D2 — Tax reversal: cumulative-anchored against the original `tax_summary` brackets, store the delta**
  (largest-remainder over original bracket-tax weights). NOT independent per-refund proportional-on-amount
  (which drifts and is shipping-ambiguous). Reconciles exactly at full refund; voucher-safe (brackets are
  already post-discount); makes G4 shipping transparent.
- **D3 — `Refund` model in `Commerce/Order`** (dep-graph: Payment must not depend on Commerce).
- **D4 — `amount_refunded` monotonic (`max`) under lock in both paths**; status recomputed from amounts.
- **D5 — No consumer `HandlePaymentRefunded`** (cancel uses existing `UpdateOrderStatus`→`SyncInventory`).
- **D6 — No `OrderStatus::Refunded`/`PartiallyRefunded`** — refunded-ness derived from `order->refunds`.
  Supersedes the round-1 "add both states" decision. (Reviewers confirmed this is the right call.) Fix the
  `Commerce/README.md` transition table, which still lists `Refunded`.
- **D7 — Actor is an explicit `RefundActor` VO**, never via `LogContext` (F21 — never populated); webhook
  refunds get a `stripe` sentinel, recorded not null.
- **D8 — Backfill: forward-only.** Pre-existing `amount_refunded > 0` payments get no synthetic `Refund`
  row; `refundedTotal()` is forward-only. (Fine pre-launch; documented, not silent.)

---

## 6. Phasing (each shippable + tested)

0. **Gateway contract + webhook parse:** `refund()` returns the id (3 implementors); `parseWebhook`
   `charge.*` branch (intent id + `amount` = charge amount + `amountRefunded` cumulative + `gatewayRefundId`);
   `WebhookPayload` + `FakePaymentGateway::simulateWebhook` new fields. (Closes F27; prereq for all.)
1. **`Refund` model** in Commerce/Order (migration w/ unique `(gateway, gateway_refund_id)`, factory, morph
   alias, `Order::refunds()`/`refundedTotal()`/`isRefunded()`); `RefundActor` VO; `RefundPaymentRequest` DTO.
2. **`RefundPayment` rework + tax reversal + restock:** txn split (gateway outside; short write txn),
   cumulative-anchored per-bracket reversed-tax delta, monotonic `amount_refunded`, status recompute,
   post-commit restock/cancel with released==picked assertion. Two Filament actions in
   `PaymentsRelationManager`.
3. **Webhook reconciliation:** lock + monotonic `amount_refunded`, status recompute, `Refund` upsert on
   `gateway_refund_id`, dispatch-once. (Closes F30/F31; dashboard refunds reconcile.)
4. **Audit + indicator:** enrich `PaymentRefunded` / add `RefundRecorded` + `RefundLogSubscriber`
   (reason/actor); `Order::isRefunded()` badge + soft shipment guard. (Closes F32; residual F28.)

Phases 0–4 are package-side, one release window (breaking changes — gateway contract, `WebhookPayload`,
`RefundPayment` signature, `PaymentRefunded` payload — fine in a single window per our no-bridge
convention). Bump in-other-worlds after (badge only). Symlink during the build if iteration is heavy.
Update `Commerce/README.md` + both `periphery.md`s in the same window.

## 7. Test plan (the false-greens to kill)

- **Charge-shaped** `charge.refunded` fixture (object=charge, `id=ch_…`, `payment_intent=pi_…`,
  `amount`, `amount_refunded`) → `gatewayReference === 'pi_…'`, `guardAmountMatches` **passes** on a
  *partial* refund, `amountRefunded`/`gatewayRefundId` populated. (Today's test at
  `StripePaymentGatewayTest.php:410` is a false-green using an intent-shaped fixture.)
- **Sequence of partial refunds** summing to full on a **mixed-rate** order (e.g. 587+587+586 over 19%+7%)
  → `Σ Refund.tax per bracket == order tax per bracket` to the cent (catches the v1 drift). A *single*
  partial is not sufficient — it passes the broken design.
- Zero-rated / out-of-jurisdiction bracket → reversed tax there is exactly 0, no borrow.
- Gateway refund succeeds then the post-gateway write throws → assert **no** orphaned charge without a
  `Refund` row reconcilable by `gateway_refund_id` (txn-split correctness).
- Out-of-order webhook: deliver a lower-cumulative webhook after a higher one → `amount_refunded` does not
  decrease, status does not regress.
- Admin refund then its echoing webhook → one `Refund` row, `amount_refunded` not double-counted, one
  `PaymentRefunded` dispatch.
- Dashboard-only refund (webhook, no admin action) → `Refund` row + `amount_refunded` from the webhook
  alone, `actor.source = stripe`, no restock.
- Partial refund with reservation picks → exactly the chosen reservations released; released==picked
  asserted; a pick that releases nothing surfaces. Refund & cancel → all reservations released once.
- `order->refunds`/`isRefunded()` correct without any order-status change; full-refund-without-cancel
  triggers the shipment soft-guard.
- Refund records `reason` + `actor` on the `Refund` row + audit log (the real subscriber, not faked).

## 8. Out of scope

Line-aware "refund these specific units" + physical-return restock; shipping-VAT itself (the **G4** fix —
but D2's `tax_summary` anchoring makes shipping's bracket transparent once G4 lands); customer-facing
refund emails (`PaymentRefunded` is the hook); dispute/chargeback (`charge.dispute.*`); historical refund
backfill (D8). Agent `ShowOrder` refund block — **deferred explicitly** (note in periphery, not built now).

## 9. Critique changelog + Related

**v1 → v2 (critique pass 1, 2026-06-04)** reversed/added: tax reversal re-spec (was wrong under sequential
partials + shipping-ambiguous); `Refund` moved Payment→Commerce (dep cycle); gateway contract returns the
refund id (anchor didn't exist); txn split (F1); monotonic locked `amount_refunded` + status-from-amounts
(regression/double-refund); `guardAmountMatches`/`mapStatus` refund handling; actor VO (F21); audit
subscriber (invariant #7 was unbuildable); two explicit actions + pick-reservations-directly (no
order_line_id); dropped `restocked_lines`; backfill forward-only; README/periphery fixes. Continuation
handles: pathology `a738c9c6e93e1342a`, tax `a19b2c17a65704acc`, API `a3f065a85096723b8`.

Audit F27–F34 in [silent-correctness-audit.md](../../../silent-correctness-audit.md) + round-2 Pass 6 in
[silent-correctness-audit-round2.md](../../../silent-correctness-audit-round2.md). VAT shape:
`vat-gross-inclusive-brief.md`. Inventory: `SyncInventoryOnOrderStatusChange`, `ReleaseReservation`.
