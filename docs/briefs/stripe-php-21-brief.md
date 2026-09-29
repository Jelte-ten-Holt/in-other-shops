# Brief — `stripe/stripe-php` 20 → 21

Status: **draft 1 — research only, nothing changed.** Home: `TODO.md` § Open ("Upgrade `stripe/stripe-php` 20 → 21").
**Date:** 2026-09-29
**Repos:** in-other-shops (driver + suite), in-other-worlds, bianka-shop-one

## 1. Verdict

The upgrade is trivial for this codebase. v21 is an SDK major with no runtime breaking change. It moves the pinned API version only inside the `dahlia` major, where Stripe promises no breaking changes. No driver or test code needs to change. All three suites pass against v21.3.2 unmodified. The whole change is three `composer.json` lines, three lockfiles and a `suggest` string.

Webhooks are unaffected. No Stripe dashboard change is required, and there is no ordering constraint between the deploy and the dashboard.

The research turned up one pre-existing money-path bug that has nothing to do with v21 (§8.1). It should not ride along with this change.

## 2. What v21 changes

Pinned API versions come from `lib/Util/ApiVersion.php:9` in each tag:
- v20.3.1 (what all three repos lock today) pins `2026-06-24.dahlia` ([v20.3.1](https://github.com/stripe/stripe-php/blob/v20.3.1/lib/Util/ApiVersion.php#L9)).
- v21.3.2 pins `2026-08-26.dahlia` ([v21.3.2](https://github.com/stripe/stripe-php/blob/v21.3.2/lib/Util/ApiVersion.php#L9)).

What each v21 release does, all from the [v21.3.2 CHANGELOG](https://github.com/stripe/stripe-php/blob/v21.3.2/CHANGELOG.md):

| Release | Change | Hits us? |
|---|---|---|
| 21.0.0 (2026-07-15) | "does not change the pinned API version". The only ⚠️ item is `ErrorObject` properties re-typed `string` → `null\|string` in phpdoc. "no runtime code has changed" (CHANGELOG:108-117) | No. We never read `ErrorObject`. `getStripeCode()` is unchanged |
| 21.0.0 | The UA "source" hash is replaced by a telemetry UUID (#2098). Unused Retry-After support is removed (#2095) | Telemetry: see §8.5. Retry-After: no. Network retries default to 0 (`lib/Stripe.php:54`) and the package never sets them |
| 21.1.0 (2026-07-29) | Pins `2026-07-29.dahlia`. Additive only: `allowed_payment_method_types` on PaymentIntents, `customer`/`payment_method` on Refund (CHANGELOG:65-98) | No |
| 21.1.1 | Suppresses warnings when a directory can't be accessed (#2110), which is the telemetry file | No |
| 21.2.0 (2026-08-10) | Adds `constructEventWithoutVerification`, `WebhookSignature::generateSignatureHeader` and a "Claude Code plugin hint" (#2117, CHANGELOG:49-60) | Hint: see §8.5 |
| 21.2.1 | Stops emitting the hint during tests (#2126) | — |
| 21.3.0 (2026-08-26) | Pins `2026-08-26.dahlia`. Adds thin-event handlers. `PaymentIntent.allowed_payment_method_types` is now always present in responses (CHANGELOG:9-44) | No |
| 21.3.1 | Hardens the API requestor against malicious URLs and header injection (#2138) | No: we build no paths from webhook data. It's a reason to take the upgrade anyway |
| 21.3.2 (2026-09-09) | Webhook secret must be non-empty (#2142) | No: `StripeGatewayServiceProvider.php:30` already refuses to register without one |

There is no "Migration guide for v21" wiki page. `github.com/stripe/stripe-php/wiki/Migration-guide-for-v21` redirects to the wiki home, while the v20 guide exists.

Stripe's changelog marks `2026-07-29.dahlia` and `2026-08-26.dahlia` "Non-breaking" throughout ([docs.stripe.com/changelog](https://docs.stripe.com/changelog)). The only entries near our calls are additive: [Refunds gain customer/payment method](https://docs.stripe.com/changelog/dahlia/2026-07-29/adds-customer-and-payment-method-details-to-the-refunds-api) and the [allowed payment method types param](https://docs.stripe.com/changelog/dahlia/2026-07-29/allowed-payment-method-types-parameter). Policy since `2024-09-30.acacia`: "monthly new API versions without breaking changes", twice-yearly majors carry breaking changes, and you "can update webhook endpoints to any API version within the same major without changing your integration" ([webhooks/versioning](https://docs.stripe.com/webhooks/versioning)).

PHP: both v20.3.1 and v21.3.2 require `php >=7.2.0`, `ext-curl`, `ext-json` and `ext-mbstring` (`composer.json` in each tag). All three repos require `^8.3`. The consumer images are `php:8.3-fpm-alpine` (`Dockerfile:23` in both) and package CI runs 8.3 (`.github/workflows/ci.yml:49`). No constraint moves.

## 3. API version and webhooks — verdict

- **Outbound calls** (create/retrieve/cancel PaymentIntent, create Refund, create Customer) will send `Stripe-Version: 2026-08-26.dahlia` instead of `2026-06-24.dahlia`. The package never overrides it: `new StripeClient($secret)` at `StripeGatewayServiceProvider.php:39`. That is a same-major move, so it is non-breaking by Stripe's policy (§2).
- **Webhook payloads** are rendered at the endpoint's own API version, or the account default if the endpoint has none. The SDK version plays no part ([webhooks/versioning](https://docs.stripe.com/webhooks/versioning)). Stripe says matching only matters for the statically typed SDKs (.NET, Java, Go). stripe-php builds whatever JSON arrives: `Webhook::constructEvent` → `Event::constructFrom($data)` (v21 `lib/Webhook.php:29-69`), with no schema. So the upgrade cannot create a mismatch between what the SDK expects and what the endpoint sends.
- The driver reads only long-stable fields: `event.id/type/data.object`, intent `id/status/client_secret/amount/currency`, charge `payment_intent/amount/amount_refunded/currency/refunds`, and refund `id/payment_intent` (`StripePaymentGateway.php:163-240`). Their phpdoc types are byte-identical between the two tags. The `PaymentIntent::STATUS_*` constants used at `:87` and `:93` still exist.
- **Operator consequence:** nothing has to change in the dashboard, before or after the deploy. Reading each endpoint's API version once is still worth doing, because §8.1 depends on it (§7).

## 4. SDK diff at the driver's touchpoints (v20.3.1 → v21.3.2)

These are unchanged, with zero diff lines: `StripeClient`, `Service/{AbstractService,CoreServiceFactory,RefundService,CustomerService}`, `StripeObject`, `Customer`, `Util/Util`, and `Exception/{ApiErrorException,InvalidRequestException,SignatureVerificationException,UnexpectedValueException}`. That covers `StripeClient::getService()`, which the package test mocks (`StripePaymentGatewayTest.php:69-71`), `InvalidRequestException::factory(...)` (`:235`) and the consumers' `ApiErrorException`/`ApiConnectionException` catches.

Changed, but not in ways we use:
- `PaymentIntentService`: phpdoc gains the `allowed_payment_method_types` param.
- `Refund`: new `@property` lines.
- `Event`: new type constants.
- `BaseStripeClient`: new methods, plus the hint call at `:104`.
- `Webhook::constructEvent`: refactored into `buildV1Event`, same behaviour. The JSON check now keys off `json_last_error()` alone.
- `WebhookSignature::verifyHeader`: empty-secret check (`:47`). There is also a new `generateSignatureHeader()` (`:143`).
- `ApiRequestor`: path and header validation, plus telemetry.
- `CurlClient::sleepTime` drops Retry-After (`:725`).

**Evidence:** I ran each suite in a scratch copy with only `stripe/stripe-php` moved to v21.3.2 (no code change, SQLite legs):

| Suite | Result |
|---|---|
| in-other-shops (full) | 1308 tests, green |
| bianka-shop-one (full) | 428 tests, green |
| in-other-worlds (full) | 1945 tests, green |

The MySQL legs were not run. This change touches no SQL.

## 5. Periphery map — everything that touches Stripe

**in-other-shops**
- `composer.json:39` has `require-dev` `^20.0`. `composer.json:43` has the `suggest` text naming ^20. `composer.lock` is on v20.3.1 (dev).
- `src/Payment/Drivers/Stripe/StripePaymentGateway.php` is the only SDK caller:
  - `paymentIntents->create` (idempotency key) at `:68`
  - `retrieve` at `:85` and `:125`
  - `cancel` at `:98`, with a `getStripeCode()` branch at `:111`
  - `Webhook::constructEvent` at `:137` and `:150`
  - `refunds->create` at `:244`
  - `customers->create` at `:257`
  - dashboard URL builders at `:266-278`
- `src/Payment/Drivers/Stripe/StripeGatewayServiceProvider.php:23-42` is the `class_exists` gate. It registers only with `payment.gateways.stripe.{secret,webhook_secret}` set.
- Config lives in `src/Payment/config/payment.php`: `:27-29` (`STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`) and `:43` (`payment.webhook_tolerance`, env `PAYMENT_WEBHOOK_TOLERANCE`, 300).
- Callers of the driver through the `PaymentGateway` contract (no SDK import):
  - `Payment/Actions/ProcessPaymentWebhook.php:35,43`
  - `Payment/Actions/RefundPayment.php:114-118`
  - `Commerce/Order/Listeners/ReconcileRefundFromWebhook.php:33`
  - `OpenPaymentSession`/`RetrievePaymentSession`
  - `commerce:expire-orders` → `cancelSession`
- Tests: `tests/Feature/Payment/Stripe/StripePaymentGatewayTest.php` mocks the services and hand-computes HMAC signatures (`:749-766`). Its `charge.refunded` fixture includes `refunds.data` (`:707-730`), which is not the live shape (§8.1). `Payment/Testing/FakePaymentGateway.php` covers everything else.
- CI: `.github/workflows/ci.yml` runs `composer install` from the lock. There is no Stripe-specific step.

**in-other-worlds**
- `composer.json:16` has `^20.0`, locked at v20.3.1.
- Webhook: `routes/api.php:6-9` (`webhooks/{gateway}`, `where('gateway','stripe')`, `throttle:60,1`) → `app/Http/Controllers/Api/WebhookController.php:13-16` → `ProcessPaymentWebhook`.
- SDK imports in `app/`: only `app/Http/Controllers/Checkout/StoreController.php:25,91`, which catches `Stripe\Exception\ApiErrorException`. It is unchanged in v21.
- `app/Http/Controllers/Checkout/PayController.php:43` catches only `RuntimeException` (§8.2).
- Gateway name: `app/Actions/Checkout/Steps/RecordPendingPayment.php:35`. Preflight: `app/Console/Commands/ProductionPreflight.php:129-151` (checks for an `sk_test_` key).
- Config/env:
  - `config/services.php:45-48` (`STRIPE_KEY`, plus `secret`/`webhook_secret` duplicates that nothing reads; only `services.stripe.key` is read)
  - `docker-compose.yml:72-74`
  - `resources/views/app.blade.php:26-27` (`stripe-key` meta)
- Front end: `resources/js/Pages/Checkout/Payment.vue:40-58` loads Stripe.js v3 from `js.stripe.com`. It is independent of the PHP SDK.
- Tests: `tests/TestCase.php:35-36` binds `FakePaymentGateway` as `stripe` for the whole suite. `WebhookControllerTest` and `CheckoutControllerTest:858` (an anonymous gateway) also cover this. **No IOW test exercises the real SDK.**

**bianka-shop-one**
- `composer.json:17` has `^20.0`, locked at v20.3.1.
- Webhook: `routes/api.php:25-26` → `app/Http/Controllers/Api/WebhookController.php:38-41`.
- SDK imports: `StoreCheckoutController.php:26` and `PayCheckoutController.php:18,57` catch `ApiErrorException`.
- Config: `config/payment.php:29-31` and `config/services.php:42-43`. Env: `.env.staging.example:52-54`. CI: `.github/workflows/deploy.yml:37-39` uses dummy keys so the provider registers.
- Front end: `resources/js/Pages/Checkout/Payment.vue:23-45` with Stripe.js v3, and `resources/views/app.blade.php:11-12`.
- Tests:
  - `tests/Feature/Payment/WebhookSignatureTest.php` uses the **real** driver with `new StripeClient('sk_test_unused')` at `:42` and real HMAC.
  - `GatewayUnavailableTest.php:23,41,62` uses `ApiConnectionException`.
  - `PaymentWebhookTest`, `WebhookRaceRegressionTest` and `BundleReservationTest` use `FakePaymentGateway`.
- Account: **bianka has no Stripe account** (`TODO.md:189`). Locally it runs on IOW's test keys (`docs/periphery.md:75`).

## 6. Build order

One sitting. There are no deprecation bridges, and no code changes anywhere.

1. **Package.**
   - Set `require-dev` to `"stripe/stripe-php": "^21.3"`.
   - Change the `suggest` text to "^21.3 — the major both consumers run".
   - Run `composer update stripe/stripe-php` and full `composer test`, then push to main and let CI go green.
   - Add a CHANGELOG line.

   No tag is needed for this alone (§9 Q1). The driver runs unchanged on v20 and v21, and a `require-dev`/`suggest` edit changes nothing a consumer resolves.
2. **Both consumers, same sitting.**
   - Set `"stripe/stripe-php": "^21.3"` and run `composer update stripe/stripe-php` (only that package; the lock diff should be one entry).
   - Run every CI suite per `ci.yml`: IOW lint + SQLite + MySQL + vite/SSR; bianka's suite.
   - Update the `docs/periphery.md` status line if it names the SDK version (neither does today).
   - Push each to main. Pushing deploys.
3. **Verify live** per §7, then close the TODO entry and move it to Recently shipped.

Nothing forces an order between steps 1 and 2. The package does not `require` stripe-php. Doing the package first keeps its suite testing the major the consumers run.

## 7. Test plan and operator steps

- **Package:** full suite on the bumped lock (already shown green in scratch, §4).
- **Consumers:** full suites, with extra attention on these already-green files:
  - IOW `CheckoutControllerTest`, `Api/WebhookControllerTest` and `RouteProtectionTest`
  - bianka `tests/Feature/Payment/*` and `Http/Controllers/Checkout/*`, where `WebhookSignatureTest` is the only real-driver HMAC test in either consumer
- **Staging, IOW (test mode):**
  1. Pay with `4242…`. Confirm the order goes Pending → Confirmed and the webhook row lands in `webhook_events`.
  2. Try a declined card (`4000 0000 0000 0002`) and confirm a retry works.
  3. Refund from the **admin** and confirm a Refund row with an `re_…` id.
  4. **Also refund one payment from the Stripe dashboard** and check whether a Refund row appears. This is the cheap check for §8.1. It is not a v21 regression test.
- **Staging, bianka:** mayangna should have no Stripe keys (`docs/periphery.md:75`, `TODO.md:189`), though `docs/deploy-coolify.md:62` says checkout needs them (§9 Q3). If there are no keys, run the local recipe instead: `stripe listen --forward-to localhost:8000/api/webhooks/stripe` (`docs/checkout-frontend-handoff.md:115-135`) on IOW's test keys, then pay, decline, admin refund.
- **Operator (Stripe dashboard → Workbench → Webhooks), read only:** note each endpoint's API version and enabled events. No change is needed for v21. If an endpoint is on a pre-dahlia version, record it; it becomes relevant at the next major. **Do not** move an endpoint's version as part of this change.

## 8. Found along the way (not part of this change)

1. **Dashboard/dispute refunds likely write no Refund row. Pre-existing and on the money path. Unverified live.**
   - Since API `2022-11-15`, `Charge.refunds` is no longer included ([changelog](https://docs.stripe.com/changelog/2022-11-15/deprecates-charges-auto-expand)), and webhook payloads omit it ([woocommerce-gateway-stripe#2497](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/2497)).
   - So `latestRefundId()` (`StripePaymentGateway.php:235-240`) returns null. `ProcessPaymentWebhook::applyRefund` still updates `payments.amount_refunded`/`status` and dispatches `PaymentRefunded` (`:168-174`). But `ReconcileRefundFromWebhook.php:33` returns early on a null refund id.
   - Result: no Refund row, no `ReverseTax`, no `RefundRecorded` audit row. `charge.refund.updated` carries the id but no cumulative, so `applyRefund` exits at `:152`. Admin-initiated refunds are unaffected, because they get the `re_…` id from the API response.
   - The package test fixture includes `refunds.data` (`StripePaymentGatewayTest.php:721-726`), which is why the suite is green.
   - Verify it with the dashboard refund in §7. If confirmed, it gets its own TODO item and fix; it is not bundled here. Fix shapes: list the charge's refunds via the API in the driver, or subscribe to `refund.created`/`refund.updated` (both exist: v21 `lib/Event.php:227-229`).
2. **IOW's pay page 500s on a Stripe outage.** `PayController.php:43` catches `RuntimeException`, but `ApiErrorException` extends `\Exception` (v21 `lib/Exception/ApiErrorException.php:8`). Bianka found and fixed the same bug (`bianka-shop-one/TODO.md:79`, `PayCheckoutController.php:57`). This is unrelated to v21, and the fix is one line plus a test.
3. **The TODO entry's locked versions are stale.** bianka and IOW both moved to v20.3.1 in the 2026-09-28 sweep (IOW `c5a9154`, bianka `fdad031`). All three repos now lock v20.3.1.
4. **Bianka's staging key situation is contradictory in the docs.** See §7 and §9 Q3.
5. **Two new SDK side effects, both harmless.**
   - v21 writes `$XDG_CONFIG_HOME/stripe/telemetry_id` or `~/.config/stripe/telemetry_id` on the first API call per process. Failures are silenced (v21 `lib/TelemetryId.php:16-58`, `lib/ApiRequestor.php:444-447`). Under FPM, `clear_env` normally leaves `HOME` unset, so nothing is written (unverified for our pool config). The CLI scheduler (`commerce:expire-orders` → cancel intent) will write it. `Stripe::setEnableTelemetry(false)` turns it off.
   - The "Claude Code plugin hint" writes one stderr line only under CLI with `CLAUDECODE` set and never under PHPUnit (`lib/Util/AgentPluginHint.php:45-62`), so it can show up in our own local `tinker`/`artisan` sessions.
6. **The next major is probably close. Unverified.** Majors so far: 2024-09-30 acacia, 2025-09-30 clover (stripe-php 18.0.0), 2026-03-25 dahlia (20.0.0) ([webhooks/versioning](https://docs.stripe.com/webhooks/versioning), CHANGELOG:245, :466). The newest tags are `v21.4.0-alpha.5`/`-beta.1`, and no v22 exists as of today. The *next* major is the one that needs a real brief: breaking API changes plus a webhook endpoint version move.

## 9. Open questions for Jelte

**Q1. Tag a package release for this?** The package change is `require-dev` + `suggest` + lock, and a tag changes nothing a consumer installs. My lean is to push it to package main and let it ride the next release. The same-version rule stays intact because the package version does not move. If you'd rather every package-side edit be tagged, it's a clean patch release (v0.71.3), and both consumers bump to it in the same sitting as their stripe bump.

**Q2. Constraint floor: `^21.3` or `^21.0`?** My lean is `^21.3`. It is what all three suites ran against, it carries the requestor hardening (21.3.1) and the empty-secret check (21.3.2), and it pins `2026-08-26.dahlia` as the minimum. It costs nothing, because the locks resolve 21.3.2 either way.

**Q3. Bianka staging: are there Stripe keys on mayangna, and whose?** `docs/periphery.md:75` says never set them in Coolify until Bianka has her own account. `docs/deploy-coolify.md:62` and `.env.staging.example:40-54` say checkout needs them, and the deploy doc records an outage from missing keys on 2026-08-05. My lean: if they are IOW's test keys, the staging test payment for bianka is fine, but the periphery line is wrong and should say so. If they are absent, the local `stripe listen` run replaces the staging payment for bianka. Either way, one of the two docs gets corrected in the bump commit.

**Q4. Do this now, or wait and go straight to the next major?** My lean is now. It is thirty minutes of edits plus two test payments, all suites are already green, and v20 stops receiving fixes once v21 is current ("older major versions … receive no additional updates", [sdks/versioning](https://docs.stripe.com/sdks/versioning)). It also leaves the next-major brief a smaller diff. Waiting only makes sense if that major lands this week and you want one staging session instead of two.

**Q5. §8.1 and §8.2: separate items?** My lean is yes to both, as fixes in their own commits after this bump. Mixing a behaviour change into a dependency bump muddles any bisect on the money path. §8.1 first needs the dashboard-refund check from §7 to confirm it is live. §8.2 is a straight port of bianka's fix and can go straight to IOW main.

**Effort:** about 30 minutes of edits and CI across three repos, plus one test-mode staging session per app (§7). No code changes.
