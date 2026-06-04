# Brief: checkout & payment-confirmation resilience (audit R1 / F1·F2·F3 + F14)

> Status: **building** on `feat/refund-domain` (stacked on the refund domain; one release window, all consumers
> pre-launch). Spans the package (`in-other-shops`: Payment + Commerce) and the consumer (`in-other-worlds`:
> checkout FlowChain + payment listeners). Decisions taken 2026-06-04 (Jelte).
>
> This brief supersedes the audit's *mechanism* descriptions for F1/F2/F3 — two agents traced the real call stacks
> and the audit was directionally right but imprecise. The corrected mechanisms are recorded below.

## Corrected mechanisms (what the code actually does)

- **F1 — gateway call inside the checkout transaction.** `ProcessCheckout` wraps all 9 steps in one DB transaction
  (`.wrapInTransaction()`); step 9 `InitiateStripePayment` → `InitiatePayment` → `StripePaymentGateway::createSession()`
  → `paymentIntents.create()` is a **network call inside the open transaction**, after stock is reserved and the
  order is persisted. Stripe succeeds but commit fails → **intent at Stripe, no order locally** (money-limbo). The
  audit's "duplicate Stripe customers on rollback" half **does not occur in checkout** — checkout passes
  `profileable: null`, so no customer is created mid-checkout (latent only for future profileable-passing flows).
- **F2/F3 — not the rollback/cancelled-order vectors the audit described.** Listeners run *after* the webhook
  commits and the email is queued post-commit, so there's no rollback-leaves-queued-email. A *Cancelled* order
  doesn't get a "confirmed" email because `UpdateOrderStatus` re-reads with a lock and throws on `Cancelled→Confirmed`,
  so the email/cart-clear after it never run. **The real bug:** in `HandlePaymentSucceeded` the email + cart-clear
  are gated only on "the transition didn't throw," **not on the transition actually happening this delivery**. For an
  **already-Confirmed** order (Stripe redelivery, or both `payment_intent.succeeded` + `charge.succeeded`), the
  `=== Pending` guard skips the transition but the code falls through and **re-sends the confirmation email + re-clears
  the cart every time** → duplicate confirmation on redelivery.
- **F14 — confirmed exactly.** `ConfirmReservation` confirms whatever Pending reservations it finds — **zero,
  silently, no guard** — if `inventory:release-expired` already released them; then the order advances to Confirmed
  **against no held stock.** Root cause: there is a **reservation-expiry cron but no order-expiry**, and the two are
  decoupled. The cron releases stock but leaves the order Pending **and never tells Stripe to stop**, so a late
  authorization succeeds against an order whose stock is already gone.

## Decisions (2026-06-04)

- **F1 → persist-then-pay + reconcile.** Never call the gateway inside an open DB transaction.
- **F14 → make it structurally impossible, not policy-handle it.** (Jelte: "why should it ever be possible?")
  Replace bare reservation-expiry with **order-expiry** that cancels the order + releases reservations + **cancels the
  Stripe intent** atomically, so a late payment can't land on an abandoned order. Hard-guard the confirm path so the
  silent version can't recur; the near-impossible residual (intent settles the instant we cancel — mainly async
  methods, not used yet) is **flagged for review, not silently confirmed** and not auto-refunded. Auto-refund is a
  future decision if/when async payment methods land.
- **Sequencing → build now, stacked on `feat/refund-domain`** (one release window).

## Design

### Part A — F1: persist, then pay
1. **One transaction:** reserve stock + create Order(Pending) + create **Payment(Pending)**. Atomic.
2. **After commit:** open the Stripe session (`paymentIntents.create`) with an **idempotency key = payment id**,
   store `gateway_reference`, return the client secret.

Failure now fails safe: if step 2 throws, a Pending order + Pending payment with no intent remains — benign, cleaned
up by order-expiry (Part C), and the idempotency key means a retry can't double-create the intent. Defense-in-depth:
the Stripe⇄local reconciliation tripwire (Part D / T4) catches any intent with no local order.

### Part B — F2/F3: confirm exactly once
New idempotent **`ConfirmOrder`** action (Commerce): locks the order; if already Confirmed → no-op, returns `false`;
else transitions Pending→Confirmed + confirms reservations, returns `true`. `HandlePaymentSucceeded` sends the email
+ clears the cart **only when `ConfirmOrder` returns `true`**. Redelivery / double-events become clean no-ops.

### Part C — F14: order-lifecycle-coupled expiry
- **`ConfirmOrder` hard guard:** an order whose expected reservations are missing is **not** advanced to Confirmed —
  it's flagged for review (no silent oversell, no auto-refund).
- **`ExpireAbandonedOrders` + `commerce:expire-orders`:** for each Pending order past its hold window with no
  successful payment, in one locked transaction — cancel the order, release its reservations, and **cancel the Stripe
  intent** (`PaymentGateway::cancelSession()`). The intent cancellation is what makes a late payment impossible.
- Both confirm and expire **lock the order**, so the race resolves to a single winner; the loser no-ops.

### Part D — defense-in-depth
Stripe⇄local reconciliation tripwire (T4): a read-only check/command listing gateway intents `succeeded` /
`requires_capture` with no matching local `Payment.gateway_reference` (catches any F1 orphan that slips the
idempotency net), in the same spirit as `inventory:reconcile` / `purchasing:reconcile-receipts`.

## Build phases (committed per phase, suite green each)

- **P1 (Payment):** extract `CreatePendingPayment` (txn-safe) + `OpenPaymentSession` (gateway call) from
  `InitiatePayment`; Stripe idempotency key; `PaymentGateway::cancelSession()` + Stripe/Fake impls. **Breaking**
  (new contract method) — all gateway impls pre-launch.
- **P2 (Commerce):** `ConfirmOrder` idempotent action + missing-reservation guard.
- **P3 (Commerce):** `ExpireAbandonedOrders` + `commerce:expire-orders`.
- **P4 (consumer):** `ProcessCheckout` persist-then-pay; `HandlePaymentSucceeded` via `ConfirmOrder` with gated
  side-effects.
- **P5:** reconciliation tripwire (T4).

## Open / deferred
- Auto-refund policy for the F14 residual — deferred until async payment methods (SEPA) are on the roadmap.
- Hold-window duration for `commerce:expire-orders` — config (`commerce.order.abandon_after`), default TBD (~30–60 min;
  must exceed the reservation TTL + a comfortable payment margin).
