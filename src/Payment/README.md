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
- `parseWebhook(Request)` → `WebhookPayload` (validated, parsed status)
- `refund(Payment, ?amount)` → void (full or partial)
- `identifier()` → string (e.g. `stripe`)

The service provider binds the contract to whichever class is configured in `payment.gateway`.

### ManagesCustomers Contract

Optional interface for gateways that support customer objects (Stripe, Mollie). Not all gateways have this concept (bank transfer, cash-on-delivery), so it's separate from `PaymentGateway`.

- `createCustomer(PaymentCustomerData)` → string (gateway customer ID)

Gateways implement both interfaces: `class StripePaymentGateway implements PaymentGateway, ManagesCustomers`.

## DTOs

- **`PaymentSession`** — redirect URL + gateway reference from session creation.
- **`PaymentCustomerData`** — email, name, phone for creating a gateway customer.
- **`InitiatePaymentResult`** — payment record + redirect URL.
- **`WebhookPayload`** — parsed webhook data.

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

### HandlePaymentWebhook

Parses the webhook via the gateway, finds the Payment by `gateway_reference`, updates status. Dispatches `PaymentSucceeded` or `PaymentFailed` if the status changed. Idempotent — duplicate webhooks with the same status are no-ops.

### RefundPayment

Validates the payment is refundable, calls the gateway, updates `amount_refunded` and status. Supports partial refunds. Dispatches `PaymentRefunded`.

## Events

- **PaymentSucceeded** — carries `Payment`. Fired when webhook confirms success.
- **PaymentFailed** — carries `Payment`. Fired when webhook confirms failure.
- **PaymentRefunded** — carries `Payment`. Fired after refund completes (full or partial).

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
