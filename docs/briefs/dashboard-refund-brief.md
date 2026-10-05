# Brief — refunds made outside the app record no Refund row (rev 3)

**Status (2026-10-05): rev 3, BUILD-READY.** The premise is observed, the fix
shape is ruled, and a second adversarial check returned "build-ready with named
fixes"; those fixes are folded in below (§ 10 lists them). One proposal is still
Jelte's to accept or drop: the tripwire in § 3.8 (R5).

Rulings: **R1** measured (§ 2). **R2** mirror the gateway's refund list (Jelte,
2026-10-05: "do what's safest"). **R3** guard the driver against events it does
not handle, same release (taken from the same steer; say so if not). **R4**
failed refunds stay a flagged item, not built here. **R5** (proposed) a
read-only tripwire for refund rows that disagree with the payment total. The
full text of rev 1, both adversarial passes and the observation is in git
(`38afafb`, `2e07702`, `1de90a1`).

Surfaced by the stripe-php 21 research
([stripe-php-21-brief.md](stripe-php-21-brief.md) § 8.1).

## 1. Verdict

A refund issued in the Stripe dashboard, or through the API by anyone but this
app, **moves the payment row and nothing else**: no `Refund` row, no VAT
reversal, no `RefundRecorded` audit row. The admin order page computes its
refund cap, its refund action's visibility and its "refunded" notices from
`Refund` rows (`EditOrder.php:52`, `:87`, `:144`), so after a dashboard refund
it still offers money that is already gone. Admin-initiated refunds are
unaffected.

Disputes are **not** part of this gap. They are a different and worse one
(§ 5.5).

## 2. Mechanism, observed

Observed 2026-10-05 on mayangna order 10 (payment 6), a test-mode partial refund
of EUR 22.00 from the dashboard:

1. Stripe sent `charge.refunded` (`evt_3UNEh8Qibh0bqUCQ0Uc6lLpl`) rendered at
   API version `2026-03-25.dahlia`. The charge carries `amount_refunded: 2200`
   and **no `refunds` key**. Stripe's `2022-11-15` changelog says why: the
   Charge no longer auto-expands refunds. Body saved as
   `tests/Fixtures/Stripe/charge.refunded.2026-03-25.dahlia.json`.
2. `StripePaymentGateway::parseChargeRefunded()` (`:198`) asks
   `latestRefundId()` (`:235-240`), which reads `$charge->refunds->data ?? []`
   and returns null.
3. `ProcessPaymentWebhook::applyRefund()` (`:148-175`) raises
   `payments.amount_refunded`, recomputes the status and dispatches
   `PaymentRefunded($payment, null, $delta)`. Seen in mayangna's admin: EUR 22.00
   refunded, `partially_refunded`.
4. `ReconcileRefundFromWebhook::handle()` (`:33`) returns on the null id.
   `RecordRefund` never runs.

The suite is green because every refund test injects a refund id that the live
payload does not carry (§ 6).

## 3. Design

### 3.1 The rule

**When a refund event moves a payment's refunded total, the order's `Refund`
rows are brought in line with the gateway's own list of refunds for that
payment.** Each refund is recorded once, under its own gateway id, with its own
amount. Nothing is derived from "which refund is this event about", because the
event does not say.

One mechanism, one path. The single-id path through `latestRefundId()` goes
away rather than staying as a second branch.

### 3.2 A second optional gateway capability

`Payment\Contracts\ListsRefunds`, beside the existing `ManagesCustomers` and
checked the same way (`instanceof`, as `InitiatePayment.php:69` does):

```php
interface ListsRefunds
{
    /**
     * The gateway's own refunds for this payment that returned money or are
     * returning it, oldest first.
     *
     * @return list<GatewayRefund>
     */
    public function listRefunds(Payment $payment): array;
}
```

`Payment\DTOs\GatewayRefund` is a readonly DTO: `id`, `amount`.

It is **not** added to `PaymentGateway`, because that interface has two
implementers outside the drivers that a new method would break:
`BasicPaymentGateway` (`tests/Feature/Payment/InitiatePaymentTest.php:336`) and
an anonymous class in IOW's `CheckoutControllerTest.php:898`.

The Stripe driver implements it with
`$this->client->refunds->all(['payment_intent' => $payment->gateway_reference, 'limit' => 100])`
and reads `->data` (one page; iterating a `Stripe\Collection` does not fetch
more). It keeps refunds whose status is `succeeded` or `pending` and returns
them **in reverse of the API's order**. Stripe returns newest first, so the
reverse is oldest first; sorting on `created` instead would leave two refunds
from the same second in arbitrary order. `failed`, `canceled`,
`requires_action` and a null status are left out: none has returned money. If
`has_more` is set it logs a warning (§ 5.8). `FakePaymentGateway` implements the
capability from its own refund ledger, which gains
`recordOutsideRefund(Payment, int $amount): string` to stand for a dashboard
refund (§ 6).

### 3.3 Where the list is fetched

In `ProcessPaymentWebhook::__invoke()`, **after parsing and before the
transaction**, so no Stripe call ever runs under the payment row lock (the
lesson of F1):

```php
$payload = $gateway->parseWebhook($request);

if ($payload === null) {            // § 3.6
    return null;
}

$gatewayRefunds = $this->gatewayRefundsFor($gateway, $gatewayName, $payload);

return DB::transaction(function () use (..., $gatewayRefunds) { ... });
```

`gatewayRefundsFor()` returns `[]` unless all of these hold:

- the event is a refund event that carries a cumulative
  (`$payload->amountRefunded !== null`);
- the gateway implements `ListsRefunds`;
- an **unlocked** read finds the payment for
  `(gateway, gateway_reference)`. Both shops share one Stripe account today, so
  each endpoint also receives the other shop's events (§ 5.6); this keeps the
  list call to payments that are ours;
- that unlocked row's `amount_refunded` is below the payload's cumulative. The
  echo of an admin refund fails this and costs no call. The shortcut is safe
  because `amount_refunded` only ever rises: if the unlocked read says the total
  would not move, the locked read inside the transaction cannot say it would.

The locked read inside the transaction stays the authority for everything else.
A list call that throws propagates before the transaction opens: no idempotency
row, a non-2xx, and Stripe retries (three days in live mode, three times within
a few hours in a sandbox).

### 3.4 The event

`PaymentRefunded` becomes `(Payment $payment, array $gatewayRefunds = [])`.
Both `gatewayRefundId` and `refundAmount` go: after § 3.5 neither has a reader.
`PaymentRefunded::dispatch($payment)` with one argument keeps working
(`LogSubscriberMappingTest.php:258`), and `PaymentLogSubscriber` reads only the
payment. The positional dispatch at `ProcessPaymentWebhook.php:174` changes with
it.

`applyRefund()` is otherwise unchanged: the payment total stays driven by the
payload's cumulative, monotonic (§ 5.3).

### 3.5 The listener, and one anchor for VAT

`ReconcileRefundFromWebhook::handle()`:

```php
$order = $event->payment->payable;

if (! $order instanceof Order) {
    return;
}

foreach ($event->gatewayRefunds as $refund) {      // oldest first
    ($this->recordRefund)(
        order: $order, payment: $event->payment,
        gatewayRefundId: $refund->id, amount: $refund->amount,
        actor: RefundActor::gateway(),
    );
}
```

`RecordRefund` stays idempotent on `(gateway, gateway_refund_id)` (unique
index, migration `:42`), which is what makes repetition harmless.

**`RecordRefund` loses its `cumulativeRefunded` parameter and derives the VAT
anchor itself:**

```php
$cumulative = max($order->refundedTotal() + $amount, $payment->amount_refunded);
```

Rev 2 let each caller pass its own cumulative: the admin path the payment
total (`RefundOrder.php:70`, from `RefundPayment.php:78`), the listener the sum
of rows. Those two numbers differ whenever the rows are ahead of the payment
total, which § 4 shows happens between two events. `ReverseTax` emits
`target(cumulative) - already reversed`, unclamped (`ReverseTax.php:60-75`), so
a caller handing in the smaller number under-reverses for good. The second
adversarial check ran it on the 1760 fixture: two dashboard refunds (500, 200),
the first event, then an admin refund of 300 before the second event reversed
95c of VAT where 119c is right, and a full-refund variant left a row with
negative tax.

With the one anchor, both quantities only ever rise (rows are only added,
`amount_refunded` is monotonic), so the anchor never falls, the delta is never
negative, and the reversed VAT always matches the larger of "what the rows say"
and "what the payment says". Which row carries which share can differ from the
chronological split; the total cannot. `RefundOrder` and the listener both stop
passing a cumulative.

### 3.6 Events the driver does not handle (R3)

`PaymentGateway::parseWebhook()` becomes `: ?WebhookPayload`. Null means
"authentic, and nothing this gateway acts on". Implementers that declare the
non-nullable type stay valid. The Stripe driver returns null, with one
`Log::info` naming the event type and id, when `data.object.object` is not
`payment_intent` and the event is not `charge.refunded`. `ProcessPaymentWebhook`
returns null and both consumers' controllers already answer 204 for that.

This replaces the cast of every unknown event to a `PaymentIntent`
(`StripePaymentGateway.php:171-189`), which today drops disputes silently and
would answer 500 on every delivery of `charge.succeeded`, `charge.updated`,
`refund.created` or `refund.updated` if anyone enabled them.

### 3.7 What is removed

- `StripePaymentGateway::latestRefundId()` and the inline `refunds` read. (This
  overturns rev 1's Q3 "keep the fast path": with the list as the one source,
  the fast path would be a second code path for a payload these accounts cannot
  produce.)
- `parseRefundUpdated()`, its `mapStatus()` arm and
  `WebhookPayload::$gatewayRefundId`. `charge.refund.updated` then falls to
  § 3.6 and is ignored. Today it writes an idempotency row and does nothing else
  (`ProcessPaymentWebhook.php:152`); Stripe documents it as deprecated and tied
  to an acquirer reference that can take seven business days or never arrive.
- `gateway_refund_id` from `FakePaymentGateway::simulateWebhook()`.

### 3.8 A tripwire for what the fix cannot reach (R5, proposed)

The fix records rows when a refund event **moves the payment total**. Three
states sit outside that trigger and nothing in § 3 reconciles them: refunds made
in the dashboard before this release (order 10 today), an admin refund whose
row write failed after the money moved (§ 5.2), and a later event that never
arrives (§ 5.3).

`commerce:reconcile-refunds`, in the same family as `inventory:reconcile`
(read-only, "reports, never repairs", exits non-zero): it lists every payment
on an order whose `amount_refunded` differs from the sum of its `Refund` rows,
skipping payments touched in the last fifteen minutes so a run landing between
two events of one sequence does not cry wolf. Consumers schedule it daily next
to their other reconcile commands, and the operator alert both apps already
raise on a failed scheduled task does the rest. Its first run on each
deployment also sizes the existing backlog.

Lean: in this release, as its own commit, one schedule line per consumer on the
bump. It is detection for exactly the cases prevention would cost a redesign.

## 4. Interleavings, walked

D = a dashboard refund, A = an admin refund from the app. "Rows" is the sum of
`Refund` rows, "total" is `payments.amount_refunded`.

| Sequence | What happens | End state |
| --- | --- | --- |
| D alone (today's case) | event moves total; list `[D]`; D recorded | rows = total |
| A alone | admin records A; echo does not move the total; no list call | rows = total |
| A, then D | D's event moves total; list `[A, D]`; A known, D recorded | rows = total |
| D1, D2, then event 1, event 2 | event 1 moves total to its snapshot; list `[D1, D2]`, both recorded; event 2 moves total; nothing left to record | rows = total; between the events rows > total |
| D1, D2, event 2 before event 1 | event 2 moves total fully; both recorded; event 1 is stale and exits | rows = total |
| D1, D2, event 1, **then A**, then event 2 and A's echo | rows are ahead when A is recorded; the § 3.5 anchor takes the rows' figure, so A's VAT is right; later events find every id known | rows = total; VAT right (the case rev 2 got wrong) |
| D, then A before D's event, **D larger than A** | A's local write and row first; D's event raises the total by the difference; list `[D, A]`; D recorded; A's echo raises the total to the full sum | rows = total; between the events rows > total |
| D, then A before D's event, **D not larger than A** | D's event is stale against A's local write and exits with no list call; A's echo moves the total; list `[D, A]`; D recorded | rows = total; converges one event later |
| Admin refund paid at Stripe, local write lost (F34) | echo moves total; list `[A]`; A recorded with the gateway as actor | rows = total; admin attribution lost, as it is lost today |
| Redelivery of any event | one list call at most, then the idempotency row or the stale check exits | unchanged |
| Two workers on two deliveries at once | each fetches its list before the lock; the lock serialises them; the unique index refuses a double row | rows = total |
| Other shop's event | no local payment; no list call | untouched |
| **Rows already behind** (a pre-release dashboard refund), then A | A's echo equals the total; no list call | **rows stay behind.** Only another outside refund, or the § 3.8 tripwire and a manual fix, reaches it (§ 5.7) |

## 5. Residuals: known, flagged, not built

1. **Admin attribution race.** If a *different* refund's event is processed in
   the moment between an admin refund's payment write (`RefundPayment`) and its
   row (`RefundOrder.php:63-73`), the listener records A with the gateway as
   actor and the admin's `RecordRefund` then finds the row and returns it: actor
   and reason lost for that row. The moment is less random than it sounds: a
   webhook arriving during `RefundPayment`'s transaction parks on the row lock
   (`ProcessPaymentWebhook.php:117`) and wakes exactly as the window opens. A's
   own echo is harmless there (it does not move the total). The money and the
   VAT are right either way. Not fortified; a `RecordRefund` rule letting an
   admin claim a gateway-recorded row is the fix if it ever bites.
2. **`RecordRefund` failing after `RefundPayment` committed** leaves an admin
   refund with no row, and its echo does not move the total, so nothing
   reconciles it. Pre-existing; the operator sees the error at the time, and
   § 3.8 would report it daily. Reconciling on every `charge.refunded`, moved
   or not, would close it but would turn residual 1 into the common path.
3. **The payment total stays payload-driven.** Between two events of a
   multi-refund sequence the rows can be ahead of `payments.amount_refunded`;
   the next event closes the difference. If that next event were lost for good,
   the rows would be right and the total behind (§ 3.8 reports it).
4. **Failed refunds (R4).** A refund recorded while `pending` that later fails
   is never undone, for admin and now also dashboard refunds. Worse, *if* Stripe
   lowers the charge's `amount_refunded` on failure, the next real refund's
   cumulative can sit at or below the stored total and be dropped as stale,
   never recorded. Whether Stripe does that is stated nowhere I read. Stripe
   documents a test card whose refund fails asynchronously
   (docs.stripe.com/testing, refunds); one test-mode run settles it, and that
   run is the first step of R4's own TODO item. The § 3.3 shortcut and
   `applyRefund`'s `max()` both assume the total never falls, so R4 has to
   revisit them.
5. **Disputes.** Three comments claim dispute coverage
   (`PaymentRefunded.php:11-12`, `ReconcileRefundFromWebhook.php:13-14`,
   `ProcessPaymentWebhookRefundTest.php:20`); they are corrected in this build.
   After § 3.6 a dispute event is logged instead of dropped silently. There is
   still no local record and no operator alert: Stripe's own e-mail and
   dashboard are the signal. Its own TODO item.
6. **The shared Stripe account.** Every event reaches both shops' endpoints.
   A settled event for the other shop's payment answers 500 by design
   (`ProcessPaymentWebhook.php:65-68`). Untouched here; it ends with Bianka's
   own account. Recorded in both consumers' periphery docs on the bump.
7. **No backfill, and an admin refund does not heal.** A payment whose rows are
   already behind (every dashboard refund made before this release) is brought
   in line only when *another outside refund* moves its total. An admin refund
   on it adds its own row and leaves the old gap (§ 4, last row); the anchor in
   § 3.5 still reverses the right VAT. Sizing and reporting these is what § 3.8
   is for; without it, run its query by hand on both databases once.
8. **More than 100 refunds on one payment** would be truncated. Not paginated;
   the driver logs a warning when the API says there is more.

## 6. Tests

Red first, in this order:

1. **The live shape through the real driver, end to end.** A signed request
   built from `charge.refunded.2026-03-25.dahlia.json` goes through
   `ProcessPaymentWebhook('stripe', …)` with the real driver and an Order-backed
   payment (6350, `pi_3UNEh8Qibh0bqUCQ0EvZ5Diw`). **The mocked client returns
   two refunds, newest first**: a later one and the fixture's
   `re_3UNEh8Qibh0bqUCQ03L4oluj` (2200). Asserts the payment at 2200, **two**
   `Refund` rows with their own ids and amounts, each with the right per-bracket
   `tax_summary`, the gateway as actor. One refund in the mock would also pass
   on rev 1's newest-id shape; two do not. On today's code: zero rows. No test
   drives `ProcessPaymentWebhook` with the Stripe driver today.
2. **The VAT sequence that broke rev 2.** D1 500, D2 200, event 1, admin 300,
   event 2, the admin's echo, on the 1760 fixture: reversed tax per bracket
   equals `{1900: 91, 700: 28}`, no row carries a negative line. Plus the
   full-refund variant (500, 960, admin 300) reversing all 210c.
3. **Driver, `listRefunds()`.** `refunds->all` pinned with the exact arguments
   (`payment_intent`, `limit`); a mixed list (succeeded, pending, failed,
   canceled, requires_action, null status; two with the same `created`) comes
   back filtered and in reverse API order; `has_more` logs the warning.
4. **Driver, parsing.** The fixture parses to cumulative 2200 against the right
   intent, with **no call** on the `refunds` service. A dispute body and a
   `refund.updated` body each parse to null without throwing.
5. **An ignored event through the action.** The signed
   `charge.refund.updated` fixture through `ProcessPaymentWebhook('stripe', …)`
   returns null and writes **zero** `webhook_events` rows. Without it a
   forgotten null check would answer 500 on every dispute.
6. **Sequences through the fake:** every row of § 4, in both event orders where
   two events exist. Each asserts **per-row amount and per-bracket VAT**, not
   only the sum and the row count: the sum-and-count assertion also passes with
   today's cumulative left in the loop.
7. **Guards.** The other shop's event makes no list call (call counter on the
   fake). An admin echo makes no list call. A list call that throws leaves no
   idempotency row and the payment untouched, and the exception escapes. **The
   fake records `DB::transactionLevel()` when its list is called, and the test
   asserts zero**; "throws, no idempotency row" alone also passes with the call
   inside the transaction.
8. **The fake stays honest.** Its webhook's cumulative is derived from its own
   refund ledger, not typed by the test (`FakePaymentGateway.php:226` today),
   and it stores the resolved amount of each refund (`:190` stores a nullable
   one).
9. **Migrated, not deleted:** the eight call sites that inject
   `gatewayRefundId` through `simulateWebhook()`
   (`ProcessPaymentWebhookRefundTest`, six; `ProcessPaymentWebhookTest:281,304`)
   move to `recordOutsideRefund()`. That includes the F34 backstop test
   (`ProcessPaymentWebhookRefundTest.php:125-145`), which
   `RefundPayment.php:57-58` cites as pinned and which the live shape defeats
   today. `StripePaymentGatewayTest.php:558-567` stops asserting an id; the
   `charge.refund.updated` test at `:571-586` inverts to "parses to null"; the
   helper at `:731-747` goes.
10. **The tripwire (if R5 is in):** clean when rows equal totals; non-zero and a
    listed payment when they differ; a payment touched a minute ago is skipped.

## 7. Periphery map

**Changes**
- `src/Payment/Contracts/PaymentGateway.php` — `parseWebhook()` return type
  becomes nullable. Implementers declaring the non-nullable type stay valid
  (checked by execution): `FakePaymentGateway.php:133`,
  `InitiatePaymentTest.php:369`, IOW `CheckoutControllerTest.php:916`.
- `src/Payment/Contracts/ListsRefunds.php`, `src/Payment/DTOs/GatewayRefund.php`
  — new.
- `src/Payment/Drivers/Stripe/StripePaymentGateway.php` — implements
  `ListsRefunds`; the guard; § 3.7 removals (`:211`, `:231`, `:235-240`).
- `src/Payment/Actions/ProcessPaymentWebhook.php` — null payload; the fetch
  step; `applyRefund()` passes the list (`:174`).
- `src/Payment/Events/PaymentRefunded.php`, `src/Payment/DTOs/WebhookPayload.php`
  — shape (§ 3.4, § 3.7).
- `src/Payment/Testing/FakePaymentGateway.php` — `ListsRefunds`,
  `recordOutsideRefund()`, a list-call counter and transaction-level probe;
  `simulateWebhook()` loses `gatewayRefundId` (`:153`, `:163`, `:227`, `:243`).
- `src/Commerce/Order/Listeners/ReconcileRefundFromWebhook.php` — § 3.5
  (`:33`, `:40-42`).
- `src/Commerce/Order/Actions/RecordRefund.php` — drops `cumulativeRefunded`,
  derives the anchor. `src/Commerce/Order/Actions/RefundOrder.php:70` and every
  test passing that argument follow.
- `src/Commerce/Order/Commands/ReconcileRefundsCommand.php` — new, if R5 is in.

**Unchanged but now reached for dashboard refunds:** `ReverseTax` → `Refund`
row → `RefundRecorded` → `Commerce\Listeners\CommerceLogSubscriber` (audit row,
gateway actor). No package listener restocks or transitions an order on
`RefundRecorded`.

**Readers whose behaviour changes because rows now exist:** the admin order
page's notices, refund cap and action visibility (`EditOrder.php:52`, `:87`,
`:144`) and the order list's refund state (`OrderResource.php:111-118`).

**`charge.refund.updated` after § 3.7:** ignored before the transaction, so no
`webhook_events` row, no payment row lock, and a null return from the action
where it returned the Payment. Nothing else read that path.

**Consumers.** Neither app listens to `PaymentRefunded` or `RefundRecorded`,
and neither app's tests simulate a refund webhook (grep, 2026-10-05). IOW's
anonymous `PaymentGateway` stays valid. bianka's `WebhookSignatureTest.php:110-112`
sends a `payment_intent` object and still gets its 204. bianka's
`OrderRefundState` and its review sweep read `payments.status`, which was always
right. IOW reads `payments.amount_refunded` in `ShowOrderController.php:74`,
`AgentTools/ShowOrder.php:229` and `ExportUserData.php:135`: unaffected. Both
apps: the version bump, plus one schedule line each if R5 is in.

**Docs.** `src/Payment/README.md` (also fixing two stale lines: `:70` says
`refund()` returns void, `:115` says `RefundPayment` dispatches
`PaymentRefunded`), the events an endpoint should carry, `docs/periphery.md`,
`CHANGELOG.md`, the stripe-php-21 brief's § 8.1 pointer, and a note on the
shared account in both consumers' periphery docs.

## 8. Release and operator steps

- Its own patch release, **v0.71.3**, before Complexity Release 2. No
  migration, no config. Both consumers bump to `^0.71.3` in the same sitting.
- **Nothing to change in the Stripe dashboard.** `charge.refunded` is already
  delivered to both endpoints. Once, for the docs: read each endpoint's enabled
  events and write the list down.
- The MySQL leg runs on the tag; dispatch it on the release commit first.

## 9. Acceptance

- § 6's tests are red before and green after; full package suite green; both
  consumer suites green on the bump.
- **Live, mayangna, test mode, after the bump deploys:**
  1. If R5 is in, run `commerce:reconcile-refunds` once on both deployments. It
     should fail and list order 10's payment (2200 refunded, no rows), plus
     whatever older test refunds exist. That is the backlog, sized.
  2. Issue a second dashboard refund on order 10. The order then has **two**
     `Refund` rows: today's unrecorded `re_3UNEh8Qibh0bqUCQ03L4oluj` for 22.00,
     picked up by the list, and the new one. Both carry a `tax_summary` and the
     gateway as actor, the order page shows the "parcialmente reembolsado"
     notice, and the refund action's cap has dropped by both amounts.
  3. One admin refund on the same order: exactly one more row, with the admin
     as actor, and no duplicate after its echo.
  4. The tripwire now runs clean for that payment.

## 10. How this brief got here

- **Rev 1 (morning).** Proposed taking the newest refund id from an API list
  inside `parseWebhook()`. Claimed the cause held "by construction" and that
  disputes were covered. Both claims were wrong or unearned.
- **Adversarial pass.** Found: disputes are a separate silent drop; the
  newest-id shape never records the second of two close refunds, and the admin
  page's cap reads those rows; unhandled events would 500 on every delivery; a
  failed refund is never undone; every refund test injects an id the live
  payload lacks; the premise was unobserved and the vendored SDK's docblock
  contradicted it.
- **Observation (evening).** One test-mode dashboard refund on mayangna: no
  `refunds` key on the charge, API `2026-03-25.dahlia`, payment row moved. Also
  seen: both endpoints receive every event; Stripe emits the refund's update as
  both `charge.refund.updated` and `refund.updated`.
- **Shapes weighed for R2.** Newest id (smallest, leaves the hole); mirror the
  list (chosen); record from `refund.created` (Stripe's documented path, no API
  call, but correctness would hang on an event ticked by hand on every
  endpoint, and a missing tick recreates this gap silently).
- **Overturned along the way:** rev 1's Q1 (newest id), Q3 (keep the inline fast
  path) and Q5 (build before measuring). Standing: fail the webhook when the
  list call fails; tag v0.71.3 and bump both consumers.
- **Second adversarial check, on rev 2.** Verdict: build-ready with named
  fixes. Folded into rev 3: one VAT anchor inside `RecordRefund` instead of a
  cumulative per caller (rev 2 under-reversed VAT when an admin refund landed
  while rows were ahead of the payment total; executed against the real
  `ReverseTax`); § 5.7 was wrong that the next refund heals a payment whose rows
  are behind (an admin refund does not), hence the tripwire proposal; the
  dashboard-then-admin row of § 4 split by which refund is larger; reverse the
  API order instead of sorting on `created`; the full list of call sites the
  shape changes break; five test corrections (two refunds in the end-to-end
  mock, per-row VAT assertions, a transaction-level probe on the list call, an
  ignored event through the action, the VAT sequence itself). Checked and
  holding: the unlocked-read shortcut, the nullable return against every
  implementer, the guard against ten event types, the SDK's list behaviour.
