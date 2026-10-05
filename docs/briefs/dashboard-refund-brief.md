# Brief — refunds made outside the app record no Refund row

**Status (2026-10-05): brief, awaiting Jelte's answers to § 8.** Surfaced by the
stripe-php 21 research ([stripe-php-21-brief.md](stripe-php-21-brief.md) § 8.1).
Not yet seen live; the mayangna test-mode dashboard refund from that brief's § 7
is the acceptance test, not the gate (§ 1 says why).

## 1. Verdict

A refund issued in the Stripe dashboard, by a dispute, or through the API by
anyone but this app **moves the payment row and nothing else**: no `Refund`
row, no tax reversal, no `RefundRecorded` audit row. Admin-initiated refunds are
unaffected.

The cause holds by construction, not by observation. Since Stripe API
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
  `chargeRefundedEventJson()` (`:707-730`) drops `refunds`; the two tests using
  it (`:558`, `:585`) then expect a `refunds->all` call and must fail on the
  unfixed driver. Add: list-call failure propagates; a payload that still
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
  shows it. An admin refund still produces exactly one row when its webhook
  echoes back.

## 8. Open questions for Jelte

**Q1. Newest refund, or match the cumulative?** Lean newest (§ 4): the
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

**Q5. Build before or after the mayangna dashboard refund?** Lean before. The
cause is structural (§ 1); the live refund then verifies the fix instead of the
bug. If you prefer to see the bug first, do the § 7 pass of the stripe brief on
the current deploy and expect no `Refund` row.
