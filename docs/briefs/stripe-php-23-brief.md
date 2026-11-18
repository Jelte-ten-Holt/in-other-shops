# Brief — `stripe/stripe-php` 21 → 23

**Status (2026-11-18): BUILT, awaiting merges and the two sandbox checkouts (§ 6).** Package on branch `chore/stripe-php-23`. IOW and bianka bump PRs opened the same day, after IOW #121 and bianka #41 merged. No driver code change.
**Repos:** in-other-shops (dev constraint, suite), in-other-worlds, bianka-shop-one. Precedent: [stripe-php-21-brief.md](stripe-php-21-brief.md).

## 1. Verdict

No code changes anywhere. SDK 22 and 23 break nothing the driver uses. The API they pin, `2026-10-28.endive`, is the first major since dahlia, but none of its breaking changes reach the parameters we send or the fields we read. All three suites pass on v23.0.0 unchanged. Every API call the driver makes was also run against the shared sandbox on endive, except `refunds->create` and `customers->create` (§ 5). The webhook endpoints stay on `2026-03-25.dahlia`, and nothing needs to change in the dashboard.

## 2. What the majors change

From the v23.0.0 CHANGELOG and the wiki migration guides for v22 and v23:

| Release | Pins | Breaking for us? |
| --- | --- | --- |
| 22.0.0 (2026-09-30) | `2026-09-30.endive` | No. It drops PHP 7.2/7.3 (we need 8.3) and widens PHPDoc param shapes (static analysis only). In the generated code, it removes `payment_method_types` from `PaymentIntent.create/confirm/update`; we send `automatic_payment_methods`. Its other ⚠️ lines cover Reserve, Tax registrations, FinancialConnections, PayTo, Radar, Subscriptions, Invoices and v2 AccountLinks, none of which we use. |
| 22.1.0 (2026-10-29) | `2026-10-28.endive` | No. It rejects whitespace-only webhook secrets, sets the idempotency key on client-configured retries, and replaces the bundled CA certificates. |
| 23.0.0 (2026-11-11) | unchanged | No. API keys no longer survive `serialize()` (we never serialize SDK objects). `StripeClient` no longer inherits `Stripe::setAccountId()` (we never set one). Signature-failure messages are clearer. |

The 21.x we leave pinned `2026-08-26.dahlia`, so the SDK already sent a different version than the endpoints use before this change.

Endive's Payments-side breaking changes (docs.stripe.com/changelog/endive):

- `payment_method_types` is no longer writable on PaymentIntents. We don't send it.
- `three_d_secure.result` gains `data_share_only`. We don't read it.
- BLIK failures get specific decline codes. We don't branch on decline codes.
- Stripe.js removes the `paymentMethodTypes` Elements option and deprecates the payment request button. Both shops load the unversioned `js.stripe.com/v3/` and build Elements from the intent's client secret, without either. That is a frontend pin that doesn't move with the PHP SDK.

## 3. API version and webhooks: measured 2026-11-18

`GET /v1/webhook_endpoints` with SDK 23:

| Endpoint | API version | Events |
| --- | --- | --- |
| `https://inotherworlds.net/api/webhooks/stripe` | `2026-03-25.dahlia` | `payment_intent.succeeded`, `.payment_failed`, `.canceled`, `charge.refunded`, `charge.refund.updated` |
| `https://mayangna.com/api/webhooks/stripe` | `2026-03-25.dahlia` | `payment_intent.succeeded`, `.payment_failed`, `charge.refunded`, `charge.refund.updated` |

A webhook body is rendered at the endpoint's version, never the SDK's. `Webhook::constructEvent` builds whatever JSON arrives and doesn't check `api_version`. So after the bump, API responses come back in endive and webhook bodies stay in dahlia. The fields the driver reads from either are the same in both versions:

- **Event:** `id`, `type`, `data.object.object`, `data.previous_attributes.status`.
- **PaymentIntent:** `id`, `status`, `amount`, `currency`, `client_secret`, `latest_charge`.
- **Charge:** `payment_intent`, `amount`, `currency`, `amount_refunded`, `balance_transaction`.
- **Refund:** `id`, `object`, `status`, `payment_intent`, `amount`, `currency`, `failure_reason`, `balance_transaction`.
- **BalanceTransaction:** `id`, `created`, `amount`, `currency`, `fee`, `net`, `exchange_rate`.

The `tests/Fixtures/Stripe/` bodies stay correct as captured.

Moving the endpoints to endive is not needed. It would mean new endpoints with new signing secrets in both shops' Coolify env, for no field we read.

## 4. What touches the driver

`src/Payment/Drivers/Stripe/StripePaymentGateway.php`, with every SDK surface it uses:

- `paymentIntents->create` (params `amount`, `currency`, `automatic_payment_methods`, `metadata`, `customer`, plus an `idempotency_key` option)
- `paymentIntents->retrieve`, including `expand: latest_charge.balance_transaction`
- `paymentIntents->cancel`, whose race path catches `InvalidRequestException` with `getStripeCode() === 'payment_intent_unexpected_state'`
- `refunds->create`, `refunds->all` (`limit: 100`, `has_more`), and `refunds->retrieve` with `expand: balance_transaction`
- `customers->create`
- `Webhook::constructEvent`, which raises `SignatureVerificationException`
- `resource_missing` read from `InvalidRequestException`
- the constants `PaymentIntent::STATUS_*`, `PaymentIntent::OBJECT_NAME`, `Refund::STATUS_*` and `Refund::OBJECT_NAME`

`StripeGatewayServiceProvider` does `new StripeClient($secret)` and never overrides `stripe_version`.

## 5. Evidence

**Suites on v23.0.0, nothing else moved:**

- Package: 1618 on SQLite.
- IOW: 2158 on SQLite, 2158 on MySQL, Pint, `composer audit --no-dev` clean.
- Bianka: 486 on SQLite with CI's dummy Stripe env and built assets (includes `WebhookSignatureTest`, the real-driver HMAC test), `composer audit --no-dev` clean.

Each consumer's lock diff is the one `stripe/stripe-php` entry (v21.3.3 → v23.0.0).

The driver tests mock the SDK services, so they prove our logic, not the SDK's wire behaviour. That is what the probe below is for.

**Sandbox probe on SDK 23 (sends endive), run without an AI-agent env:**

- `paymentIntents->create` with the driver's exact params and idempotency key works. A replay with the same key returns the same `pi_…`.
- `retrieve` works and `cancel` returns `canceled`. A second cancel raises `InvalidRequestException` with `payment_intent_unexpected_state`.
- The `latest_charge.balance_transaction` expand returns a `Charge` and a `BalanceTransaction` with int amounts.
- `refunds->all` and `refunds->retrieve` with expand work. An unknown id raises `resource_missing`.
- Zero `Stripe-Notice` warnings came back.

The probe never made a payment succeed. One would reach both endpoints as an unknown payment, and the package answers that with a non-2xx on purpose, so Stripe would retry it for days. Its one cancelled intent reached only IOW's endpoint, which drops an unknown cancel with a 2xx.

**Not exercised by the probe:** `refunds->create` and `customers->create`. The checkouts in § 6 cover both.

## 6. Sandbox checkout per shop (operator; the Stripe iframe needs a human)

After each shop's bump PR merges and deploys. Do it once on inotherworlds.net and once on mayangna.com:

1. Use an email that has never paid in that shop, so the checkout also creates a Stripe customer. Add a product and check out.
2. Pay with `4242 4242 4242 4242`, any future expiry, any CVC.
3. Check the confirmation page. In `/admin`, the order should leave Pending and its payment should show Succeeded.
4. In the Stripe dashboard (sandbox), open Workbench → Webhooks → that shop's endpoint. The `payment_intent.succeeded` delivery should show 200.
5. Refund the order from `/admin`. The Refund row should carry a `re_…` id, and the `charge.refunded` delivery should show 200. This is the one write the probe couldn't make.

## 7. Found along the way

1. **A `Stripe-Notice` response header would 500 a checkout, and this is true before the bump too.** Both 21.x and 23.x turn that header into `trigger_error(…, E_USER_WARNING)` after the request has succeeded. Laravel's error handler turns a warning into an `ErrorException`, so `createSession` would throw with the intent already created. 22.0 added `STRIPE_SUPPRESS_NOTICES=true`, which silences notices in test and sandbox mode when no AI agent is detected. No notice came back on any probe call. The failure would be loud and immediate (the first checkout that gets one fails), so this records it and adds no fix.
2. **The SDK's AI-agent detection** reads `CLAUDECODE` and similar variables and reports them to Stripe. That only matters for local CLI runs from an agent session, never for the containers.

## 8. Rulings (lean first)

- **Q1. Tag a release?** Lean: no, same as Q1 of the 21 brief. The package change is the `require-dev` constraint, the `suggest` text and two comments, so nothing a consumer installs changes. Both shops stay on v0.75.0, and this rides the next release, where the CHANGELOG line goes in under "Also in this release". If you want one anyway, it's v0.75.1, and both shops then need `composer update jelte-ten-holt/in-other-shops` in a follow-up.
- **Q2. Move the endpoints to endive?** Lean: no (§ 3).
- **Q3. Set `STRIPE_SUPPRESS_NOTICES=true` in both shops' Coolify env?** Lean: no for now (§ 7.1). It is a one-line env change if a notice ever shows up.
