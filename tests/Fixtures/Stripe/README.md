# Stripe webhook fixtures

Real event bodies, captured from the Stripe dashboard's delivery view or, since 2026-11-01, from `GET /v1/events` with `Stripe-Version: 2026-03-25.dahlia` (the endpoints' version). Use these
instead of hand-built payloads when a test's point is the live payload shape.

| File | Captured | Source |
| --- | --- | --- |
| `charge.refunded.2026-03-25.dahlia.json` | 2026-10-05 | test-mode partial refund (EUR 22.00 of 63.50) issued from the dashboard, mayangna order 10. **The charge carries no `refunds` key.** |
| `charge.refund.updated.2026-03-25.dahlia.json` | 2026-10-05 | the same refund, one second later, when its acquirer reference arrived |
| `charge.refunded.refund-fail-card.2026-03-25.dahlia.json` | 2026-11-01 | R4 run: an API payment with `pm_card_refundFail` (EUR 10.00), fully refunded by API. `amount_refunded` 1000 |
| `charge.refund.updated.failed.2026-03-25.dahlia.json` | 2026-11-01 | the same refund about two minutes later, `status` `failed`, `failure_reason` `expired_or_canceled_card`, `failure_balance_transaction` set. Both shops subscribe to this event |
| `refund.failed.2026-03-25.dahlia.json` | 2026-11-01 | the same failure as `refund.failed` (same object). Neither shop subscribes to it |
| `charge.after-refund-failure.api-read.2026-03-25.dahlia.json` | 2026-11-01 | **not an event**: `GET /v1/charges/{id}` a minute after the failure. `amount_refunded` is back to **0**, `refunded` false, and **no** `charge.refunded` or `charge.updated` announced the drop |

Scrubbed to `REDACTED`: receipt URLs, the request id and idempotency key, the
card fingerprint, authorization code, network transaction id and the refund's
acquirer reference. Object ids are the real test-mode ids. Nothing here is live
data.

A signature computed for these bodies must be computed over the exact bytes a
test sends, so load the file, re-encode if you change it, and sign that string.
