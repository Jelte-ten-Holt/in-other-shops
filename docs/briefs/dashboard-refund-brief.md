# Brief — refunds made outside the app record no Refund row

**Status (2026-10-05, evening): the premise is OBSERVED (§ 9.8) — R1 done.
Rev 2 waits on rulings R2–R4 only.** Earlier the same day: rev 1 + adversarial
pass (§ 9), not build-ready. Two
claims of rev 1 are withdrawn in place (the dispute claim, and "holds by
construction"); § 8's Q1 and Q5 are superseded by § 9's rulings R1–R4. The
build waits on those rulings and on one live observation (R1). Surfaced by the
stripe-php 21 research ([stripe-php-21-brief.md](stripe-php-21-brief.md) § 8.1).
Not yet seen live; the mayangna test-mode dashboard refund from that brief's § 7
is the acceptance test, not the gate (§ 1 says why).

## 1. Verdict

A refund issued in the Stripe dashboard or through the API by anyone but this
app (~~or by a dispute~~ — withdrawn, § 9.1: a dispute is not a refund and takes
a different, worse path) **moves the payment row and nothing else**: no `Refund`
row, no tax reversal, no `RefundRecorded` audit row. Admin-initiated refunds are
unaffected.

~~The cause holds by construction, not by observation.~~ **Withdrawn, § 9.3:
expected, not observed.** The vendored SDK's own docblock still says the ten
most recent refunds are on the Charge by default, and no code reads or pins the
endpoint's API version. One live event body settles it. Since Stripe API
`2022-11-15` a Charge no longer carries `refunds`, and webhook payloads cannot
expand it. The webhook endpoint's API version is what renders the payload, and
both shops' Stripe accounts were created in 2026, so their endpoints are on a
version years past that cut. The driver's `latestRefundId()` therefore reads an
empty list and returns null, and the reconcile listener returns early on null.

The package suite is green because the `charge.refunded` fixture in
`StripePaymentGatewayTest` includes `refunds.data`, which the live payload does
not. That test has never been red against the real shape.

## 2. Mechanism, by line

1. `StripePaymentGateway::parseWebhook()` (`:163`) routes `charge.refunded` to
   `parseChargeRefunded()` (`:198`), which sets `gatewayRefundId:
   $this->latestRefundId($charge)` (`:211`).
2. `latestRefundId()` (`:235-240`) reads `$charge->refunds->data ?? []` and
   returns `null` when the list is absent. Live payloads have no `refunds` key.
3. `ProcessPaymentWebhook::applyRefund()` (`:148-175`) still raises
   `payments.amount_refunded` monotonically, recomputes `status`, and dispatches
   `PaymentRefunded($payment, null, $delta)`. The payment row is correct.
4. `ReconcileRefundFromWebhook::handle()` (`:33`) returns on
   `$event->gatewayRefundId === null`. `RecordRefund` never runs, so no `Refund`
   row, no `ReverseTax`, no `RefundRecorded`.
5. `charge.refund.updated` → `parseRefundUpdated()` (`:221`) carries the id but
   no cumulative, so `applyRefund` exits at `:152` by design. It cannot fill the
   gap and is not changed here.

Why the id matters: `RecordRefund` is idempotent on `(gateway,
gateway_refund_id)` (`refunds` table unique at migration `:42`). That anchor is
what lets the admin path's row and the echoing webhook converge on one record.
A row with a null id would break that convergence (a MySQL unique index admits
any number of NULLs), so "record it anyway" is not a fix.

## 3. Fix shapes

**A. The driver fills the gap from the API (lean).** In `latestRefundId()`,
when the payload carries no `refunds`, call
`$this->client->refunds->all(['charge' => $charge->id, 'limit' => 100])` and
take the newest refund whose `status` is not `failed` or `canceled`. Stripe
lists newest first. One extra API call per `charge.refunded` delivery, on a
path that already makes outbound calls (`refunds->create`, `paymentIntents->*`).
Same mechanic, same classes, same event, same listener; no dashboard change.
`refunds` is already a mocked service in the driver test, so the test needs no
new scaffolding. ~15 lines.

**B. Subscribe to `refund.created` / `refund.updated`.** Both exist in v21's
`Event` constants. They carry the refund id and amount, but no cumulative, so
`applyRefund` would need a second code path to add the refund's own amount, and
the endpoint's enabled events have to be edited in the dashboard on both
accounts. More code and an operator step for the same outcome. Not preferred.

**C. Retrieve the charge with `expand[]=refunds`.** Works (the property is
still expandable on a retrieve), but it is the same extra call as A with a
larger response. A is the narrower call.

## 4. Design points inside shape A

- **Which refund.** Newest non-failed. The one case this misattributes: two
  refunds issued before the first one's webhook is processed. Event 1 then
  carries refund 2's id; event 2 hits the idempotency anchor and records nothing.
  Result: one `Refund` row with event 1's delta under refund 2's id;
  `payments.amount_refunded` stays right because it is cumulative. At this scale
  (manual dashboard refunds by one operator) it is a flag, not a fortification.
  The fortified version walks the list oldest-first accumulating `amount` until
  it equals `amount_refunded`; ~8 more lines. § 8 Q1.
- **When the list call fails.** `parseWebhook()` runs before
  `ProcessPaymentWebhook` opens its transaction (`:43` vs `:45`), so an
  `ApiErrorException` propagates as a non-2xx and Stripe retries the delivery
  for up to three days. That is the right failure: loud, self-healing, no
  idempotency row written. No catch. § 8 Q2.
- **Payload that does carry `refunds`.** Keep the existing two-line read as the
  first branch. An endpoint pinned to a pre-2022-11-15 version would still work
  without an API call, and the fast path costs nothing. § 8 Q3.
- **`charge.refund.updated`** stays as it is: a no-op on amounts, by the design
  comment at `:215-219`.

## 5. Periphery map

What this touches, and what fires or depends downstream.

**Driver and callers**
- `src/Payment/Drivers/Stripe/StripePaymentGateway.php` — `latestRefundId()`
  gains the API fallback; `parseChargeRefunded()` unchanged.
- `src/Payment/Actions/ProcessPaymentWebhook.php` — unchanged; now receives a
  non-null id from live payloads.
- `src/Payment/Events/PaymentRefunded.php` — unchanged shape
  (`payment, ?gatewayRefundId, ?refundAmount`).
- `src/Commerce/Order/Listeners/ReconcileRefundFromWebhook.php` — unchanged; its
  early return now rarely fires (only non-Order payables).
- `src/Commerce/Order/Actions/RecordRefund.php` → `ReverseTax`, `Refund` row,
  `RefundRecorded`. Unchanged. **These now run for dashboard refunds.**

**What fires once the row exists** (the behaviour change, listed so nobody is
surprised): `RefundRecorded` → `Commerce\Listeners\CommerceLogSubscriber`
(audit row, actor `gateway`); `PaymentRefunded` →
`Payment\Listeners\PaymentLogSubscriber` (already firing today). No package
listener restocks or transitions the order on `RefundRecorded`; the Filament
order page's refund column and actions (`OrderResourceRefundColumnTest`,
`EditOrderRefundActionsTest`) read `Refund` rows and will start showing
dashboard refunds. **Consumers:** neither IOW nor bianka listens to
`PaymentRefunded` or `RefundRecorded` (`app/` and `config/` grep, 2026-10-05);
bianka's `WebhookController` docblock only names the event. bianka's
`App\Support\Commerce\OrderRefundState` and its review-invitation sweep read
`payments.status`, which `applyRefund` already keeps right, so they are
unaffected by the gap and by the fix. Nothing to change in either app beyond
the version bump.

**Tests**
- `tests/Feature/Payment/Stripe/StripePaymentGatewayTest.php` —
  `chargeRefundedEventJson()` (`:707-730`) drops `refunds`; the one test using
  it (`:558`; `:585` belongs to the `charge.refund.updated` test — corrected,
  § 9.5) then expects a `refunds->all` call and must fail on the unfixed driver. Add: list-call failure propagates; a payload that still
  carries `refunds` makes no API call.
- `ProcessPaymentWebhookRefundTest`, `ProcessPaymentWebhookTest:281,304`,
  `RecordRefundTest`, `RefundPaymentTest` — use `FakePaymentGateway` or hand-built
  payloads, untouched.

**Docs**
- `src/Payment/README.md` and `docs/periphery.md` (verification log line;
  "what fires" gains dashboard refunds), `CHANGELOG.md`. The README is already
  stale here and gets fixed in the same commit: `:70` says `refund()` returns
  void (it returns the gateway refund id), `:115` says `RefundPayment`
  dispatches `PaymentRefunded` (it deliberately does not; the webhook action
  does).
- The stripe-php-21 brief § 8.1 points here once this is built.

**Operator**
- Nothing in the Stripe dashboard. `charge.refunded` must be among the
  endpoint's enabled events on both accounts; the stripe-php-21 brief's § 7
  asks Jelte to read the endpoints anyway, so confirm it in the same look.

## 6. Release

Its own patch release, **v0.71.3**, per the stripe brief's Q5 ruling (money-path
behaviour change, never bundled with a dependency bump). Both consumers bump to
`^0.71.3` in the same sitting (push-and-bump). Complexity Release 2 stays
v0.72.0 after it. No migration, no config, no consumer code.

## 7. Acceptance

- The retargeted driver tests are red before the fix and green after, with the
  full package suite (SQLite; the MySQL leg runs on the tag).
- Both consumer suites green on the bump.
- **Live, mayangna test mode, after the bump deploys:** one refund from the
  Stripe dashboard produces a `Refund` row with the `re_…` id, a `tax_summary`,
  `actor_source = gateway`, and a `RefundRecorded` audit row; the order page
  shows it. ~~An admin refund still produces exactly one row when its webhook
  echoes back.~~ (Cannot fail, § 9.5: the echo exits before the listener runs.)

## 8. Open questions for Jelte

**Q1 (superseded by § 9 R2). Newest refund, or match the cumulative?** Lean newest (§ 4): the
misattribution needs two refunds inside one webhook delivery window, the payment
total is right either way, and the fortified walk is more code for a case we
have not seen. Say so if you would rather have the walk.

**Q2. Let a failed list call fail the webhook?** Lean yes. Stripe retries, no
idempotency row is written, and a quiet null would recreate exactly the gap this
fixes.

**Q3. Keep the inline `refunds` fast path?** Lean yes, two lines, and it keeps
old endpoint versions working without a call.

**Q4. Tag v0.71.3 and bump both consumers now?** Lean yes; it is one sitting and
it lands before Release 2 so the two never share a release.

**Q5 (superseded by § 9 R1; the lean below is reversed). Build before or after the mayangna dashboard refund?** Lean before. The
cause is structural (§ 1); the live refund then verifies the fix instead of the
bug. If you prefer to see the bug first, do the § 7 pass of the stripe brief on
the current deploy and expect no `Refund` row.

## 9. Adversarial pass (2026-10-05)

An independent reviewer checked rev 1 against the code, both consumers and the
vendored SDK. § 2's mechanism holds (a `refunds`-less `charge.refunded` parses to
a null id through the real driver, and the listener skips it). The rest of this
section is what did not hold. Each claim below was re-read in the code before
being recorded here.

### 9.1 Disputes are not refunds — rev 1 was wrong, and so are three comments

`charge.dispute.*` has no branch in `parseWebhook()`, so it falls into the
PaymentIntent cast (`StripePaymentGateway.php:171-189`): the reference becomes
the dispute's `dp_…` id, the status maps to Pending (`:288-292`), no payment
matches, and `ProcessPaymentWebhook.php:65-70` answers 204 with no ledger row
and no log. A dispute creates no Refund object and no `charge.refunded`, so
shape A would find nothing either. **Money lost to a dispute leaves no local
trace at all.** The same wrong claim ("dashboard / dispute / API") sits in
`PaymentRefunded.php:11-12`, `ReconcileRefundFromWebhook.php:13-14` and the
header of `ProcessPaymentWebhookRefundTest.php:20`; all three get corrected in
the build. Disputes become their own TODO item (R3 covers the cast, not a
dispute feature).

### 9.2 The two-refund case is worse than rev 1's § 4 said

With "newest non-failed", refund 2's amount and VAT reversal are **never
recorded**, and `Order::refundedTotal()` (`Order.php:160-163`, a sum of Refund
rows) under-reports for good. The admin page reads that sum for the
fully-refunded notice (`EditOrder.php:52`), the refund cap (`:87`) and the
action's visibility (`:144`). The window is not one delivery: any delayed first
event (a deploy, or a 500 in backoff, which § 4's throw-on-failure creates)
widens it to hours, because the payload's cumulative is a snapshot at event time
and the list is live at processing time. The Q1 walk fixes dashboard-then-
dashboard but **not dashboard-then-admin**: the admin write raises
`amount_refunded` first, event 1 exits at `ProcessPaymentWebhook.php:158-160`,
and event 2 resolves to the admin's existing id. Nothing today reconciles
`sum(refunds.amount)` against `payments.amount_refunded`.

### 9.3 The premise is unobserved, and Q5's lean skipped the only falsifier

`vendor/stripe/stripe-php/lib/Service/RefundService.php:15-17` says "The 10 most
recent refunds are always available by default on the Charge object", and
`Charge.php:49` still declares `$refunds`. That may be stale API description
text, but it is the opposite of rev 1's premise, and the endpoint's API version
is dashboard state that no code reads (`Event::$api_version` is ignored). Rev 1
called the cause structural and leaned towards building first. That was a claim
I had not verified. See R1.

### 9.4 Failure modes rev 1 missed

- **Events outside the handled set 500 on every delivery.** `charge.succeeded`,
  `charge.updated`, `refund.created` and `refund.updated` (status `succeeded`)
  map to Succeeded with a `ch_…`/`re_…` reference, miss, and throw at
  `ProcessPaymentWebhook.php:65-68`. Shape B would have triggered exactly this.
  No doc in any of the three repos lists which events the endpoint may carry, so
  rev 1's "Operator: nothing" was wrong: the enabled-event set is a real
  constraint. See R3.
- **A refund that is recorded while `pending` and later fails is never undone.**
  `parseRefundUpdated()` never reads `$refund->status`, the event exits at
  `ProcessPaymentWebhook.php:152`, and `max()` at `:156` discards a lower
  cumulative. This exists today for admin refunds; the fix extends it to
  dashboard refunds, and `RefundPayment.php:97` then blocks re-issuing from the
  admin. Whether `amount_refunded` drops on failure is a Stripe fact nobody here
  has checked. See R4.
- **No backfill.** Dashboard refunds made before the fix get no row; with
  shape A the next refund's row would carry the whole cumulative's VAT
  (`RecordRefund.php:93-101`). Test data only today.
- Throwing on a failed list call (§ 4) ties the payment-row update, which works
  today, to the lookup. Stands as designed; note that test mode retries far
  fewer times than live mode's three days.

### 9.5 Test trust

- The `:558` test does go red once the fixture drops `refunds`. But it passes on
  a broken driver unless `all` is pinned with the exact arguments, and a
  one-element list proves neither the selection nor the status filter.
- § 7's "admin refund still one row" cannot fail (struck above).
- **Every row-recording test injects the refund id through
  `FakePaymentGateway::simulateWebhook()`** (`:220-247`), including the F34
  backstop (`ProcessPaymentWebhookRefundTest.php:125-145`) that
  `RefundPayment.php:57-58` cites as pinned. On the live payload shape that
  backstop records zero rows today. No test drives `ProcessPaymentWebhook` with
  the Stripe driver. The build adds one: a signed, live-shaped `charge.refunded`
  through `ProcessPaymentWebhook('stripe', …)` asserting the Refund row.
- Rev 1's § 5 miscounted the fixture's callers (corrected in place).

### 9.6 Periphery rev 1 missed

- The admin order page's cap, visibility and notice derive from Refund rows
  (§ 9.2), so the fix changes admin behaviour, not only display: after a
  dashboard refund the page stops offering money that is already gone.
- IOW reads `payments.amount_refunded` in `ShowOrderController.php:74`
  (customer-facing), `AgentTools/ShowOrder.php:229` and `ExportUserData.php:135`.
  Unaffected by the fix; exposed to the failed-refund case.
- Checked and holding: both consumers let a webhook exception escape as a 500
  (IOW `bootstrap/app.php:113-115`, bianka's JSON rendering only), pinned by
  `WebhookControllerTest.php:71` and `WebhookRaceRegressionTest.php:82`. The
  `charge` filter exists and the list is documented newest-first.

### 9.7 Rulings needed before rev 2 (these replace § 8's Q1 and Q5)

**R1 — DONE 2026-10-05, see § 9.8: the live `charge.refunded` body carries no
`refunds` key.** Original text: Measure first. One test-mode refund from the Stripe dashboard on
mayangna's *current* deploy. From the event's page in the dashboard, keep the
`charge.refunded` body and its `api_version`, and note the endpoint's enabled
events. The body becomes the test fixture. If it carries `refunds`, this brief
is void and the gap is elsewhere. Lean: do this before any code; it is one step
of the stripe brief's § 7 pass that is owed anyway.

**R2 — revised in § 9.8 (three shapes now, lean unchanged).** Original text:
Fix shape. (a) Newest id only: ~15 lines, with § 9.2's hole left open
and recorded. (b) **Mirror the gateway's list:** the driver lists the intent's
refunds outside the transaction, the list rides the payload and
`PaymentRefunded` as an additive property, and the listener records every
refund not yet recorded with its own amount, oldest first. Rows then equal the
gateway's list under any interleaving, dashboard-then-admin included, and
earlier unrecorded refunds backfill on the next event. ~40 lines plus tests.
Lean (b): the purpose of this fix is correct refund and VAT records, and (a)
knowingly leaves a hole in the same records.

**R3. Events the driver does not handle.** Guard `parseWebhook()` on
`data.object.object === 'payment_intent'`; anything else is dropped with a log
line naming the event type, instead of being cast. That turns the dispute drop
from silent into logged and removes the 500-on-every-delivery risk. Lean: same
release, own commit, and document the allowed event set in `Payment/README.md`
and both consumers' deploy docs. A dispute *feature* is not in scope.

**R4. Failed refunds.** Flag as its own TODO item, not in this release. It
needs a Stripe fact first (does `amount_refunded` drop when a refund fails), and
card refunds rarely fail. Lean: flag only.

Q2 (fail the webhook on a failed list call), Q3 (keep the inline fast path) and
Q4 (v0.71.3, both consumers bumped) stand as leaned.

### 9.8 Live observation and Stripe documentation (2026-10-05, after the pass)

**R1 is done.** Jelte issued a test-mode partial refund (EUR 22.00 of 63.50)
from the Stripe dashboard against mayangna order 10 (payment 6,
`pi_3UNEh8Qibh0bqUCQ0EvZ5Diw`). What the dashboard and the admin showed:

- **`charge.refunded`** (`evt_3UNEh8Qibh0bqUCQ0Uc6lLpl`, source Dashboard),
  rendered at API version **`2026-03-25.dahlia`**, answered 204. The charge
  carries `amount_refunded: 2200` and **no `refunds` key**. § 1's premise is now
  observed. Body saved, scrubbed, as
  `tests/Fixtures/Stripe/charge.refunded.2026-03-25.dahlia.json`.
- **The payment row moved** as § 2 step 3 predicts: mayangna's admin shows the
  payment at EUR 22.00 refunded, `partially_refunded`. The missing Refund row
  follows from the code path (§ 2 step 4); its visible sign, the absence of the
  "Este pedido está parcialmente reembolsado" notice on the order page
  (`EditOrder.php:52-66`), has not been confirmed by eye.
- **One second later Stripe emitted the refund's update twice:** as
  `charge.refund.updated` (`evt_…0dMbXMKm`, delivered to both endpoints, 204)
  and as `refund.updated` (`evt_…0Zt2HHj0`). The refund object carries its
  `id`, its own `amount: 2200`, `payment_intent` and `status: succeeded`.
  Fixture saved alongside the other.
- **Both shops share one Stripe account, so every event is delivered to both
  endpoints** (`inotherworlds.net` and `mayangna.com`). For refund events the
  other shop drops the delivery quietly. For a *settled* event
  (`payment_intent.succeeded`, `payment_intent.payment_failed`) the other shop
  finds no payment and throws `UnmatchedWebhookPaymentException` by design
  (`ProcessPaymentWebhook.php:65-68`; IOW pins the 500 in
  `WebhookControllerTest.php:59`). So every mayangna test payment should be a
  500 at IOW, and the reverse. **Expected from the code; not yet looked at in
  the dashboard.** It ends when Bianka has her own account. It matters to this
  brief because a fix that calls the Stripe API at parse time would also call
  it for the other shop's events.

**Stripe documentation, read the same day:**

- Changelog `2022-11-15`: the Charge object no longer auto-expands refunds;
  `Charge.refunds` became nullable. It can still be expanded by hand, which
  Stripe advises against. Confirms § 1; the SDK docblock quoted in § 9.3 is
  stale text.
- Event reference: `charge.refunded` — "Listen to `refund.created` for
  information about the refund." `refund.created` occurs whenever a refund is
  created, `refund.failed` whenever one fails. `charge.refund.updated` fires only
  "on selected payment methods", and the refunds guide marks it deprecated in
  favour of `refund.updated`. The acquirer reference that triggered today's
  update can take up to seven business days in live mode and never arrives for
  a reversal, so **`charge.refund.updated` cannot be what records a row.**
- Dispute events carry a `dispute` object (confirms § 9.1).
- Retries: live mode up to three days with exponential backoff; sandbox events
  three times within a few hours.
- A failed refund moves to status `failed` and is announced by `refund.failed`;
  the money returns to the Stripe balance, up to 30 days later. Whether the
  charge's `amount_refunded` drops is still not stated anywhere I read (R4).

**R2, revised: three shapes.**

- **(a) Newest id only.** As before: smallest, and § 9.2's hole stays open.
- **(b) Mirror the gateway's list (lean).** Refined by today's finding: fetch the
  intent's refunds only after the payment is matched, and outside the row lock,
  so the other shop's events cost nothing. The listener records every refund not
  yet recorded, with its own amount, oldest first. Everything lives in code;
  nothing depends on dashboard state; earlier unrecorded refunds backfill.
- **(c) Record each row from `refund.created`.** Stripe's documented path: the
  event carries the id and the refund's own amount, so no API call at all.
  `charge.refunded` keeps moving the payment total. Costs: the event has to be
  enabled by hand on every endpoint (two today, Bianka's own account later),
  and a missing tick recreates this exact gap silently; no backfill; needs R3's
  guard first, or the other shop answers 500.

Both (b) and (c) need one more rule that rev 1 never had to think about: when
the webhook records an admin refund's row before the admin path does, the admin
must still end up as the row's actor, with the reason kept. Today that race
cannot arise, because the echo of an admin refund exits before the listener
runs. Rev 2 specifies it; the targeted adversarial check attacks it.

**Lean (b):** it is the only shape whose correctness does not depend on
something an operator ticked in a dashboard.
