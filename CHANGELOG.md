# Changelog

All notable changes to `jelte-ten-holt/in-other-shops` are documented here.
This file starts at v0.52.0; earlier releases are recorded in the git tag
history and in `docs/periphery.md`'s "Last verified" log.

The format is loosely [Keep a Changelog](https://keepachangelog.com/); the
package is pre-1.0, so minor versions may carry breaking changes (all consumers
are pre-launch — single-release-window policy, no deprecation bridges).

## v0.53.0 — 2026-07-29

Additive. No breaking changes; a collection without the new key behaves exactly
as before.

### Added
- **Per-collection media type restriction.** A `media.collections` entry may now
  carry `types => ['embed']` (strings or `MediaType` cases) to narrow which of
  Upload / External / Embed the admin offers for that collection, alongside the
  existing `cover => false`. New public `MediaSchema::collectionTypes()` is the
  source of truth the Select is wired to.

  The motivating case: a video-embed collection that still offers "Upload" lets
  an editor put a JPEG where a player URL belongs. The upload succeeds, and the
  breakage surfaces later as a dead embed on a public page. Narrowing the
  options removes the wrong answer from the form rather than validating it after
  the fact.

  An unrecognised or empty list falls back to every type rather than none, so a
  config typo can't lock an editor out of their own media form. Save-time
  enforcement comes from Filament, which validates a Select against its own
  option list.

## v0.52.1 — 2026-07-23

Fast-follows from the v0.52.0 supervisor acceptance (recorded in the bianka
money-path brief). No breaking changes.

### Fixed
- **Order lines join the delete-gating (M3 class).** `OrderLinesRelationManager`
  delete + bulk-delete are now hidden unless the owner order `isDeletable()` —
  the lines of a paid order are the durable record of what was sold and must
  survive the same way the order and its addresses do.
- **A late success can no longer un-refund a payment.** The
  `ProcessPaymentWebhook` regression guard now also refuses
  `Refunded`/`PartiallyRefunded` → `Succeeded` (a success delivery delayed past
  a dashboard refund would have flipped the status back and fired
  `PaymentSucceeded` — confirm + ship — for a refunded sale). Refused
  transitions are now `Log::info`'d (payment id, reference, both statuses,
  event id) so out-of-order deliveries are observable.
- **Claiming a guest cart clears its TTL.** v0.52.0 stamps every guest cart's
  `expires_at`; nothing slides it for owner carts, so a claimed cart read as
  dead after 30 idle days — making products in a customer's cart deletable.
  `ClaimCart` now nulls the stamp on claim.
- **`cancelSession` only maps the real state-race.** The
  `InvalidRequestException` → `PaymentNotCancelableException` mapping now
  requires Stripe code `payment_intent_unexpected_state`; any other invalid
  request (`resource_missing`, malformed id — the SDK maps every 400/404 to the
  same class) is rethrown as the real error instead of masquerading as
  "intent live" and being warn-logged forever by the expiry sweep.

### Also shipped in this release — bianka AUDIT-2026-07-04 package wave (PR #8)

Written 2026-07-04, merged into this release window. All additive.

- **SCALE-4** — `coverImage()`/`firstMedia()` resolve from the eager-loaded
  `media` relation when present (0 extra queries; parity with the query path
  pinned incl. pivot-position ordering and collection filters). Kills ~1–2
  queries per card on every storefront render in both consumers.
- **BUG-3** — default `getCartableLabel()` is null-safe: name → slug →
  `"{morph alias} #{key}"`. A name-less cartable no longer 500s cart renders.
- **BUG-7** — `FindOrCreateCartItemStep` absorbs the two-tab double-add race
  via `createOrFirst` + increment-on-lost-race (savepoint-wrapped; the
  surrounding FlowChain transaction survives on all drivers).
- **DRY-1** — new `Pricing::defaultPriceList()` / `forgetDefaultPriceList()`
  request-scoped resolver (Octane/queue-safe, null memoized). Consumers should
  delete their hand-rolled copies on bump (11 across the two apps, tracked in
  the consumers' TODOs).

## v0.52.0 — 2026-07-22

Money-path hardening (from bianka-shop-one AUDIT-2026-07-22). Breaking where noted.

### Fixed
- **Payment status never regresses (M6).** `ProcessPaymentWebhook` no longer
  lets a settled payment (Succeeded / Refunded / PartiallyRefunded) fall back to
  Failed/Pending on an out-of-order webhook delivery.
- **Unmatched settled webhooks retry instead of vanishing (M10).** A succeeded
  or failed event with no matching payment now throws the new
  `UnmatchedWebhookPaymentException` (⇒ non-2xx ⇒ gateway retry) instead of
  returning null (⇒ 204 ⇒ event lost). Thrown before the idempotency insert, so
  a retry lands once the `gateway_reference` exists. **Breaking:** consumers
  whose webhook controller previously relied on a 2xx for an unmatched event now
  see a 5xx — which is the intended "please retry" signal.
- **Cancel-race maps cleanly (M11).** `StripePaymentGateway::cancelSession` maps
  an `InvalidRequestException` from the cancel call (intent raced to succeeded)
  to `PaymentNotCancelableException` rather than letting a raw Stripe error
  escape.
- **Order-expiry is resilient (M5, partial).** `ExpireAbandonedOrders` wraps each
  order so one that throws (gateway outage, driver fault) is logged and skipped
  rather than starving the sweep; the not-cancelable path now logs a warning
  naming the order id and gateway reference.

### Added
- **`Order::isDeletable()`** (Pending AND no payment row) — the D4 delete
  predicate. Filament `EditOrder` delete, and `OrderAddressesRelationManager`
  delete/bulk-delete, are gated on it; the `OrderResource` bulk delete is
  removed (M3/M16). **Breaking:** bulk-deleting orders from the table is no
  longer offered.
- **Guest-cart TTL (D7).** New `commerce.cart.ttl_days` (env `CART_TTL_DAYS`,
  default 30); `Cart` stamps `expires_at` on guest-cart creation and slides it
  forward on every cart write, so `commerce:prune-carts` (previously a no-op for
  want of a stamp) reclaims abandoned guest carts. Owner carts are never stamped
  or pruned.
- **`FakePaymentGateway::markSessionErroring()`** test helper — force
  `cancelSession()` to throw a generic gateway error (distinct from the
  live/`PaymentNotCancelableException` path).

### Notes
- `guardAmountMatches` compares against `intent.amount` (authorized), not
  `amount_received` (captured); equal for the full-capture flow. A received-
  amount guard is deferred (comment in `StripePaymentGateway::parseWebhook`).
