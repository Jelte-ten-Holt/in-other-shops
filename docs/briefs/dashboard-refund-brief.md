# Brief — refunds made outside the app record no Refund row (rev 2)

**Status (2026-10-05): rev 2. The premise is observed; the fix shape is ruled.
Awaiting a targeted adversarial check on §§ 3–6, then build-ready.**

Rulings: **R1** measured (§ 2). **R2** mirror the gateway's refund list (Jelte,
2026-10-05: "do what's safest"). **R3** guard the driver against events it does
not handle, same release (taken from the same steer; say so if not). **R4**
failed refunds stay a flagged item, not built here. Rev 1, its adversarial pass
and the live observation are condensed in § 10; the full text is in git
(`38afafb`, `2e07702`).

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

`Payment\DTOs\GatewayRefund` is a readonly DTO: `id`, `amount`, `createdAt`.

It is **not** added to `PaymentGateway`, because that interface has two
implementers outside the drivers that a new method would break:
`BasicPaymentGateway` (`tests/Feature/Payment/InitiatePaymentTest.php:336`) and
an anonymous class in IOW's `CheckoutControllerTest.php:898`.

The Stripe driver implements it with
`$this->client->refunds->all(['payment_intent' => $payment->gateway_reference, 'limit' => 100])`,
keeps refunds whose status is `succeeded` or `pending`, and returns them sorted
by `created` ascending. `failed`, `canceled` and `requires_action` are left out:
none of them has returned money. `FakePaymentGateway` implements it from its own
refund list, which gains `recordOutsideRefund(Payment, int $amount): string` to
stand for a dashboard refund (§ 6).

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

`PaymentRefunded` becomes
`(Payment $payment, ?int $refundAmount = null, array $gatewayRefunds = [])`.
`refundAmount` stays the delta this event applied. `gatewayRefundId` is removed;
its only reader was the listener. `PaymentRefunded::dispatch($payment)` with one
argument keeps working (`LogSubscriberMappingTest.php:258`), and
`PaymentLogSubscriber` reads only the payment.

`applyRefund()` is otherwise unchanged: the payment total stays driven by the
payload's cumulative, monotonic (§ 5.3).

### 3.5 The listener

`ReconcileRefundFromWebhook::handle()`:

```php
$order = $event->payment->payable;

if (! $order instanceof Order || $event->gatewayRefunds === []) {
    return;
}

$known = $order->refunds()->where('gateway', $payment->gateway)->pluck('gateway_refund_id')->all();
$cumulative = $order->refundedTotal();

foreach ($event->gatewayRefunds as $refund) {      // oldest first
    if (in_array($refund->id, $known, true)) {
        continue;
    }

    $cumulative += $refund->amount;

    ($this->recordRefund)(
        order: $order, payment: $payment,
        gatewayRefundId: $refund->id, amount: $refund->amount,
        cumulativeRefunded: $cumulative, actor: RefundActor::gateway(),
    );
}
```

`RecordRefund` is unchanged. Its idempotency on `(gateway, gateway_refund_id)`
(unique index, migration `:42`) is what makes repetition harmless, and its tax
reversal is anchored on the cumulative it is given minus what earlier rows
already reversed (`RecordRefund.php:91-103`), so the reversed VAT sums correctly
whatever order the rows arrive in. Which row carries which share of the VAT can
differ from the chronological split when an admin row was written first; the
total cannot.

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
| D, then A before D's event | A's local write and row first; D's event raises the total by the difference; list `[D, A]`; D recorded; A's echo raises the total to the full sum | rows = total; between the events rows > total |
| Admin refund paid at Stripe, local write lost (F34) | echo moves total; list `[A]`; A recorded with the gateway as actor | rows = total; admin attribution lost, as it is lost today |
| Redelivery of any event | one list call at most, then the idempotency row or the stale check exits | unchanged |
| Other shop's event | no local payment; no list call | untouched |

## 5. Residuals: known, flagged, not built

1. **Admin attribution race.** If D's event is processed in the moment between
   an admin refund's payment write (`RefundPayment`) and its row
   (`RefundOrder.php:63-73`), the listener records A with the gateway as actor
   and the admin's `RecordRefund` then finds the row and returns it: actor and
   reason lost for that row. It needs a dashboard refund pending, an admin
   refund at the same time, and a webhook landing inside a few milliseconds. The
   money and the VAT are right. Not fortified; a `RecordRefund` rule letting an
   admin claim a gateway-recorded row is the fix if it ever bites.
2. **`RecordRefund` failing after `RefundPayment` committed** leaves an admin
   refund with no row, and its echo does not move the total, so nothing
   reconciles it. Pre-existing; the operator sees the error at the time.
   Reconciling on every `charge.refunded`, moved or not, would close it but
   would turn residual 1 from a rare race into the common path.
3. **The payment total stays payload-driven.** Between two events of a
   multi-refund sequence the rows can be ahead of `payments.amount_refunded`;
   the next event closes the difference. If that next event were lost for good,
   the rows would be right and the total behind.
4. **Failed refunds (R4).** A refund recorded while `pending` that later fails
   is never undone, for admin and now also dashboard refunds. Stripe announces
   it with `refund.failed`; whether the charge's `amount_refunded` drops is
   stated nowhere I read. Its own TODO item.
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
7. **No proactive backfill.** Earlier unrecorded refunds on a payment are
   recorded the next time a refund event moves that payment's total, not before.
8. **More than 100 refunds on one payment** would be truncated. Not paginated.

## 6. Tests

Red first, in this order:

1. **The live shape through the real driver, end to end.** A signed request
   built from `charge.refunded.2026-03-25.dahlia.json` goes through
   `ProcessPaymentWebhook('stripe', …)` with the real driver, an Order-backed
   payment (6350, `pi_3UNEh8Qibh0bqUCQ0EvZ5Diw`) and the mocked client
   returning one refund (`re_3UNEh8Qibh0bqUCQ03L4oluj`, 2200). Asserts the
   payment at 2200 and partially refunded, **and one `Refund` row** with that
   id, that amount, a `tax_summary` and the gateway as actor. On today's code:
   zero rows. No test drives `ProcessPaymentWebhook` with the Stripe driver
   today.
2. **Driver, `listRefunds()`.** `refunds->all` pinned with the exact arguments
   (`payment_intent`, `limit`); a mixed list (succeeded, pending, failed,
   canceled, requires_action; out of order) comes back filtered and oldest
   first.
3. **Driver, parsing.** The fixture parses to cumulative 2200 against the right
   intent, with **no call** on the `refunds` service. A dispute body, a
   `refund.updated` body and the saved `charge.refund.updated` fixture each
   parse to null without throwing.
4. **Sequences through the fake**, each ending with
   `sum(refunds.amount) === payments.amount_refunded` and the expected row
   count: every row of § 4, in both event orders where two events exist.
5. **Guards.** The other shop's event makes no list call (call counter on the
   fake). A list call that throws leaves no idempotency row and the payment
   untouched, and the exception escapes.
6. **Migrated, not deleted:** the eight call sites that inject
   `gatewayRefundId` through `simulateWebhook()`
   (`ProcessPaymentWebhookRefundTest`, six; `ProcessPaymentWebhookTest:281,304`)
   move to `recordOutsideRefund()`. That includes the F34 backstop test
   (`ProcessPaymentWebhookRefundTest.php:125-145`), which
   `RefundPayment.php:57-58` cites as pinned and which the live shape defeats
   today.

## 7. Periphery map

**Changes**
- `src/Payment/Contracts/PaymentGateway.php` — `parseWebhook()` return type.
- `src/Payment/Contracts/ListsRefunds.php`, `src/Payment/DTOs/GatewayRefund.php`
  — new.
- `src/Payment/Drivers/Stripe/StripePaymentGateway.php` — implements
  `ListsRefunds`; the guard; § 3.7 removals.
- `src/Payment/Actions/ProcessPaymentWebhook.php` — null payload; the fetch
  step; `applyRefund()` passes the list.
- `src/Payment/Events/PaymentRefunded.php`, `src/Payment/DTOs/WebhookPayload.php`
  — shape (§ 3.4, § 3.7).
- `src/Payment/Testing/FakePaymentGateway.php` — `ListsRefunds`,
  `recordOutsideRefund()`, a list-call counter; `simulateWebhook()` loses
  `gatewayRefundId`.
- `src/Commerce/Order/Listeners/ReconcileRefundFromWebhook.php` — § 3.5.

**Unchanged but now reached for dashboard refunds:** `RecordRefund` →
`ReverseTax` → `Refund` row → `RefundRecorded` →
`Commerce\Listeners\CommerceLogSubscriber` (audit row, gateway actor). No
package listener restocks or transitions an order on `RefundRecorded`.

**Readers whose behaviour changes because rows now exist:** the admin order
page's notices, refund cap and action visibility (`EditOrder.php:52`, `:87`,
`:144`) and the order list's refund state (`OrderResource.php:111-118`).

**Consumers.** Neither app listens to `PaymentRefunded` or `RefundRecorded`,
and neither app's tests simulate a refund webhook (grep, 2026-10-05). IOW's
anonymous `PaymentGateway` (`CheckoutControllerTest.php:898`) stays valid: the
new capability is optional and a non-nullable `parseWebhook()` satisfies the
nullable contract. bianka's `OrderRefundState` and its review sweep read
`payments.status`, which was always right. IOW reads `payments.amount_refunded`
in `ShowOrderController.php:74`, `AgentTools/ShowOrder.php:229` and
`ExportUserData.php:135`: unaffected. Both apps: version bump only.

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
- **Live, mayangna, test mode, after the bump deploys:** issue a second
  dashboard refund on order 10. The order then has **two** `Refund` rows:
  today's unrecorded `re_3UNEh8Qibh0bqUCQ03L4oluj` for 22.00, picked up by the
  list, and the new one. Both carry a `tax_summary` and the gateway as actor,
  the order page shows the "parcialmente reembolsado" notice, and the refund
  action's cap has dropped by both amounts.
- Then one admin refund on the same order: exactly one more row, with the admin
  as actor, and no duplicate after its echo.

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
