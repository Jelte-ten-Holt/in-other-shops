# Changelog

All notable changes to `jelte-ten-holt/in-other-shops` are documented here.
This file starts at v0.52.0; earlier releases are recorded in the git tag
history and in `docs/periphery.md`'s "Last verified" log.

The format is loosely [Keep a Changelog](https://keepachangelog.com/); the
package is pre-1.0, so minor versions may carry breaking changes (all consumers
are pre-launch — single-release-window policy, no deprecation bridges).

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
