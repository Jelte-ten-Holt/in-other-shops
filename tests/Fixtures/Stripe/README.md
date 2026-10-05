# Stripe webhook fixtures

Real event bodies, captured from the Stripe dashboard's delivery view. Use these
instead of hand-built payloads when a test's point is the live payload shape.

| File | Captured | Source |
| --- | --- | --- |
| `charge.refunded.2026-03-25.dahlia.json` | 2026-10-05 | test-mode partial refund (EUR 22.00 of 63.50) issued from the dashboard, mayangna order 10. **The charge carries no `refunds` key.** |
| `charge.refund.updated.2026-03-25.dahlia.json` | 2026-10-05 | the same refund, one second later, when its acquirer reference arrived |

Scrubbed to `REDACTED`: receipt URLs, the request id and idempotency key, the
card fingerprint, authorization code, network transaction id and the refund's
acquirer reference. Object ids are the real test-mode ids. Nothing here is live
data.

A signature computed for these bodies must be computed over the exact bytes a
test sends, so load the file, re-encode if you change it, and sign that string.
