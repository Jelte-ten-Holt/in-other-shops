# Payment Domain

Gateway-agnostic payment processing. Creates payment records, delegates to a configured gateway for session creation and webhook handling, and fires events on status changes. The domain depends only on Currency — it has no knowledge of Commerce, Orders, or any specific payable model.

## Models

### Payment

Polymorphic — attached to any model implementing `HasPayments` via `payable` morph.

| Column | Type | Notes |
|---|---|---|
| payable_type / payable_id | morph | The model being paid for |
| amount | integer | Cents |
| amount_refunded | integer | Cumulative refunded (cents) |
| currency | string(3) | Cast to `Currency` enum |
| status | string | Cast to `PaymentStatus` enum |
| gateway | string | Gateway identifier (e.g. `stripe`) |
| gateway_reference | string, nullable | Provider's session/payment ID |
| gateway_data | json, nullable | Raw provider metadata |

Helpers: `isSuccessful()`, `isRefunded()`, `isPartiallyRefunded()`.

### PaymentProfile

Stores gateway customer references on any model (primarily Customer). Enables Stripe to pre-fill customer details and reuse the same gateway customer across orders.

| Column | Type | Notes |
|---|---|---|
| profileable_type / profileable_id | morph | Typically Customer |
| gateway | string | `stripe`, `mollie`, etc. |
| gateway_customer_id | string | Provider's customer ID |
| gateway_data | json, nullable | Extra provider metadata |

Unique constraint: `[profileable_type, profileable_id, gateway]` — one profile per gateway per model.

## Contracts & Traits

**`HasPayments`** / **`InteractsWithPayments`** — interface + trait for any payable model. Methods: `payments()` (morphMany), `latestPayment()`, `totalPaid()`, `getPaymentTotalDue()`, `isPaid()`. Implementers must provide `getPaymentTotalDue()` returning the amount owed (e.g. Order returns `$this->total`) — the Payment domain cannot infer the owing amount from payments alone. `totalPaid()` sums `amount - amount_refunded` across Succeeded and PartiallyRefunded payments; `isPaid()` returns `totalPaid() >= getPaymentTotalDue()`.

**`HasPaymentProfiles`** / **`InteractsWithPaymentProfiles`** — interface + trait for models that store gateway customer references. Methods: `paymentProfiles()` (morphMany), `paymentProfileFor(string $gateway)`.

```php
use InOtherShops\Payment\Contracts\HasPayments;
use InOtherShops\Payment\Concerns\InteractsWithPayments;

final class Order extends Model implements HasPayments
{
    use InteractsWithPayments;
}
```

```php
use InOtherShops\Payment\Contracts\HasPaymentProfiles;
use InOtherShops\Payment\Concerns\InteractsWithPaymentProfiles;

final class Customer extends Model implements HasPaymentProfiles
{
    use InteractsWithPaymentProfiles;
}
```

## PaymentGateway Contract

The domain ships a `PaymentGateway` interface. Projects implement it per provider:

- `createSession(Payment, returnUrl, cancelUrl, ?gatewayCustomerId)` → `PaymentSession` (redirect URL + reference)
- `retrieveSession(Payment)` → `PaymentSession` (current redirect URL / clientSecret for an existing payment — used on reload, tab restore, deep-link)
- `parseWebhook(Request)` → `?WebhookPayload` (validated, parsed status). `null` means the request is authentic and carries nothing the gateway acts on; the caller answers it as received and records nothing.
- `refund(Payment, ?amount)` → string, the gateway's refund id (full or partial)
- `identifier()` → string (e.g. `stripe`)

The service provider binds the contract to whichever class is configured in `payment.gateway`.

### ManagesCustomers Contract

Optional interface for gateways that support customer objects (Stripe, Mollie). Not all gateways have this concept (bank transfer, cash-on-delivery), so it's separate from `PaymentGateway`.

- `createCustomer(PaymentCustomerData)` → string (gateway customer ID)

### ListsRefunds Contract

Optional interface for gateways that can list the refunds made against a payment, whoever made them. Separate from `PaymentGateway` for the same reason as `ManagesCustomers`, and checked the same way (`instanceof`).

- `listRefunds(Payment)` → `list<GatewayRefund>`: the gateway's own refunds for the payment that returned money or are returning it, oldest first.

`ProcessPaymentWebhook` calls it when a refund event would move a payment's refunded total (see below). A gateway without it still has its refund webhooks move the payment total; no refund rows are recorded from them.

Gateways implement the interfaces they support: `class StripePaymentGateway implements ListsRefunds, ManagesCustomers, PaymentGateway`.

## DTOs

- **`PaymentSession`** — redirect URL + gateway reference from session creation.
- **`PaymentCustomerData`** — email, name, phone for creating a gateway customer.
- **`InitiatePaymentResult`** — payment record + redirect URL.
- **`WebhookPayload`** — parsed webhook data. A refund event carries the payment's cumulative refunded amount; it names no single refund.
- **`GatewayRefund`** — one refund as the gateway records it: its id there and its amount.
- **`RefundResult`** — what `RefundPayment` returns: the gateway refund id, the amount, the payment's cumulative refunded after it.

## Actions

### InitiatePayment

Creates a Payment record, optionally resolves/creates a gateway customer profile, calls the gateway to create a session (passing the gateway customer ID if available), stores the gateway reference. Returns `InitiatePaymentResult` with the payment and redirect URL.

Optional parameters:
- `profileable` — model implementing `HasPaymentProfiles` (e.g. Customer) to store/lookup gateway customer IDs.
- `customerData` — `PaymentCustomerData` DTO for creating a new gateway customer if no profile exists.

Profile resolution flow:
1. If profileable has an existing profile for this gateway → use its `gateway_customer_id`
2. If no profile and gateway implements `ManagesCustomers` and customerData provided → call `createCustomer()`, store new `PaymentProfile`
3. Pass `gateway_customer_id` (if any) to `createSession()`

### RetrievePaymentSession

Asks the payment's gateway for its current `PaymentSession` (clientSecret / redirectUrl). Used when re-rendering a payment page on reload, tab restore, or deep-link — no new gateway session is created. Does not modify the Payment record.

### ProcessPaymentWebhook

Verifies the signature, parses the webhook via the gateway, finds the Payment by `gateway_reference` under a row lock, records the event id in the idempotency ledger (`webhook_events`) and updates the payment. Dispatches `PaymentSucceeded` or `PaymentFailed` if the status changed. Returns `null`, with no ledger row, for an event the gateway does not handle and for an informational event that matches no payment.

**Refund events.** A refund event carries the payment's cumulative refunded amount and does not say which refund it is about. `amount_refunded` follows that cumulative and never falls; the status is recomputed from the amounts. When the event would move the total and the gateway implements `ListsRefunds`, the action fetches the gateway's refund list for the payment and dispatches `PaymentRefunded` with it. The list is fetched after an unlocked payment lookup and **before** the database transaction, so no gateway call runs under the row lock; if the call throws, nothing has been written and the gateway retries the delivery. The echo of a refund this app issued does not move the total, so it costs no call and dispatches nothing.

### RefundPayment

Validates the payment is refundable, calls the gateway, updates `amount_refunded` and status. Supports partial refunds. Returns a `RefundResult`. It dispatches no event and records no refund row: recording is the caller's job (Commerce's `RefundOrder`), so Payment stays free of Commerce.

## Events

- **PaymentSucceeded** — carries `Payment`. Fired when webhook confirms success.
- **PaymentFailed** — carries `Payment`. Fired when webhook confirms failure.
- **PaymentRefunded** — carries `Payment` and `gatewayRefunds` (`list<GatewayRefund>`, oldest first; empty when the gateway cannot list refunds). Fired when a refund webhook moves the payment's refunded total, which is a refund made outside the app (the Stripe dashboard, another API client). **Not** fired by `RefundPayment`. Commerce listens and records a `Refund` row for every listed refund that has none.

## Stripe webhook events

The events a Stripe endpoint should carry:

| Event | What the driver does |
|---|---|
| `payment_intent.succeeded` | payment → Succeeded, `PaymentSucceeded` |
| `payment_intent.payment_failed` | payment → Failed, `PaymentFailed` |
| `payment_intent.canceled` | payment → Cancelled |
| `charge.refunded` | payment's refunded total and status; `PaymentRefunded` when the total moves |

Any other `payment_intent.*` event is read through the intent's own status. Everything else is ignored: answered as received, logged at `info` with the event type and id, no ledger row. That includes `charge.refund.updated`, `refund.*`, `charge.succeeded`, `charge.updated` and **`charge.dispute.*`**. A dispute is not a refund and leaves no local record; Stripe's own e-mail and dashboard are the signal for one.

`charge.refunded` is rendered without the charge's `refunds` list (Stripe API `2022-11-15` and later), which is why the driver lists refunds through the API instead of reading them off the event. A captured body is in `tests/Fixtures/Stripe/`.

If one Stripe account serves more than one shop, every endpoint receives every shop's events. An informational or refund event for a payment that is not this shop's is answered as received; a `payment_intent.succeeded` or `payment_failed` for one answers 500 by design (the same answer an early delivery for our own payment gets, so Stripe retries it).

## Filament

**PaymentsRelationManager** — read-only table for any resource with a `payments` relationship. Columns: reference, gateway, amount, refunded, status, date. Not `final` — subclassable for project customization.

## Config

```php
// config/payment.php
'gateway' => env('PAYMENT_GATEWAY'),            // FQCN of PaymentGateway implementation
'webhook_tolerance' => env('PAYMENT_WEBHOOK_TOLERANCE', 300),
```

## Design Decisions

- **Polymorphic `payable`** — Payment attaches to any model, not just Order. This keeps the domain extractable and lets future payable models (subscriptions, invoices) use it without modification.
- **Polymorphic `profileable`** — PaymentProfile attaches to any model, not just Customer. Same extraction principle.
- **Gateway as a contract** — the domain never imports a specific provider. The project implements the gateway and configures it via `.env`. Testing uses a `FakePaymentGateway`.
- **ManagesCustomers as separate interface** — not all gateways have customer concepts. The optional interface keeps the main `PaymentGateway` contract clean and avoids forcing no-op implementations.
- **ListsRefunds as separate interface** — same shape, and adding a method to `PaymentGateway` would break every implementer outside the drivers.
- **Refund rows mirror the gateway's list** — nothing is derived from "which refund is this event about", because the event does not say. One mechanism: there is no second path reading a refund id off a webhook.
- **Events over return values** — webhook handling fires events so project-level listeners can react (confirm order, send email, etc.) without the domain knowing about those concerns.
- **Idempotent webhooks** — providers often send duplicate webhooks. The handler skips events when the status hasn't changed.
- **Redirect-only flow (for now)** — `PaymentSession` currently carries a `redirectUrl`, assuming all gateways use hosted payment pages (Stripe Checkout, Mollie, Adyen hosted). This covers the immediate use case but will need to evolve — see Future section.

## Dependencies

- **Currency** — `Currency` enum for the `currency` cast.

## Future

- **Client-side payment flows** — The current `PaymentSession` DTO assumes redirect-based hosted pages. To support embedded forms (Stripe Elements, Adyen Drop-in), introduce a `PaymentFlowType` enum (`redirect`, `client`) and extend `PaymentSession` with an optional `clientSecret` field. The `PaymentGateway` contract stays unchanged — the DTO carries the flow type and the payment page component branches on it. The webhook side is unaffected. This is the next major evolution of the domain.
- PaymentMethod model (saved cards, stored methods)
- Filament refund action button
- Payment retry (re-initiate for failed/expired attempts)
