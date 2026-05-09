# Shipment lifecycle — domain design

**Status:** active design, build authorised. Captures decisions reached 2026-05-09 in conversation with the `in-other-worlds` consumer. The package's TODO previously had "Split OrderStatus" as a deferred item — promoted because (a) it's the do-it-right window before launch, (b) the change is breaking and breaking pre-launch is cheap, (c) several consumer-side pieces (transactional emails, tracking-number surface, returns flow) are blocked on this design.

## Origin

The Payment domain has a state machine; Shipment doesn't. The current `Shipment` model is metadata-only (`cost`, `currency`, `method`, polymorphic owner) and `OrderStatus` is conflating three machines into one enum (`Pending → Confirmed → Processing → Shipped → Delivered → Refunded → Cancelled`). No tracking number, no lifecycle events, no support for split shipments or customer returns.

## Decisions

### Three independent state machines (Option A)

`OrderStatus` drops its fulfillment-shaped and payment-shaped values:

```
OrderStatus (after):
  Pending     cart submitted, awaiting payment
  Confirmed   payment succeeded, order accepted
  Cancelled   admin or customer cancelled before fulfillment
```

`Refunded` belongs on `PaymentStatus` (already there). `Processing`, `Shipped`, `Delivered` move to `ShipmentStatus`.

This mirrors Shopware's three-state-machine model (Order / Transaction / Delivery) and keeps each enum's vocabulary coherent. Breaking change to `OrderStatus` and to consumers reading those values — fine, single-consumer pre-launch.

### `ShipmentStatus` enum, parallel to `PaymentStatus`

```
ShipmentStatus:
  Pending           created, awaiting pick/pack
  Ready             label generated, packed, awaiting carrier handover
  InTransit         handed to carrier, en route
  Delivered         carrier confirmed delivery
  ReturnedToSender  refused at delivery, came back to warehouse
  Lost              carrier admits loss; triggers reship or refund flow
```

Same shape as `PaymentStatus`: `label()`, `color()`, `allowedTransitions()`, `canTransitionTo()`. Allowed transitions:

- `Pending → Ready, Lost`
- `Ready → InTransit, Lost`
- `InTransit → Delivered, ReturnedToSender, Lost`
- `Delivered → ` (terminal)
- `ReturnedToSender → Pending` (reship after issue resolved), `Lost`
- `Lost → ` (terminal — replacement is a new Shipment)

### Schema additions to `shipments`

- `status` (enum, default `pending`)
- `carrier` (nullable string — "DHL", "DPD", "DeutschePost"; consumer-defined free-form)
- `tracking_number` (nullable string)
- `tracking_url` (nullable; can be derived from carrier + number via a config map, persisted for non-derivable cases)
- `shipped_at`, `delivered_at` (nullable timestamps; analytics/SLA tracking, populated on transition)

`carrier` stays a free-form string. The `config/shipping.php` carriers map provides validation hints and tracking-URL templates but doesn't enforce — keeps the package consumer-flexible.

### Carrier configuration: config templates only

`config/shipping.php` gains a `carriers` map:

```php
'carriers' => [
    'dhl' => [
        'name' => 'DHL',
        'tracking_url_template' => 'https://nolp.dhl.de/nextt-online-public/en/search?piececode={tracking_number}',
    ],
    'dpd' => [
        'name' => 'DPD',
        'tracking_url_template' => 'https://tracking.dpd.de/status/en_DE/parcel/{tracking_number}',
    ],
    // ...
],
```

`DispatchShipment` auto-populates `tracking_url` from `carriers[$carrier]['tracking_url_template']` when both `carrier` and `tracking_number` are set. The persisted `tracking_url` column means one-off carriers with non-templatable URLs (signed query strings, customer-token-bearing URLs) still work — pass an explicit `$trackingUrl` to `DispatchShipment` and the template lookup is skipped.

No pluggable carrier-provider interface. Templates cover the typical case; if a future carrier needs auth-signed URLs or live API lookup, that's a focused extension at the time, not infrastructure built for nobody.

### `morphOne` → `morphMany`

The `HasShipment` contract's relationship becomes plural:

- `HasShipment::shipments(): MorphMany`
- `InteractsWithShipment` updates accordingly.

Convenience accessors for the single-shipment-typical case live in the consumer or as a default helper (`shipments()->latest()->first()`), not in the contract.

This is the change with the largest blast radius. The simple case (one order, one shipment) costs slightly more code at consumer call sites — `$order->shipments->first()->trackingNumber` instead of `$order->shipment->trackingNumber`. Acceptable for support of split shipments, partial fulfillment, and inbound returns.

### `OrderLine ↔ Shipment` relationship

Ship from day one: a `shipment_items` table with `(shipment_id, order_line_id, quantity)`. Without it, every Shipment implicitly contains all OrderLines and split shipments are unrepresentable. Backfilling later means migrating existing data — better to land it together with the lifecycle change.

### Actions

Mirror the Payment domain's pattern:

- `CreateShipment(Order $order, ShippingMethod $method, ?Collection $orderLines = null)` — creates a Shipment in `Pending`. `orderLines` defaults to all lines for full-shipment; subset for split.
- `MarkShipmentReady(Shipment)` — `Pending → Ready`.
- `DispatchShipment(Shipment, string $trackingNumber, string $carrier, ?string $trackingUrl = null)` — `Ready → InTransit`, sets `shipped_at = now()`.
- `MarkShipmentDelivered(Shipment)` — `InTransit → Delivered`, sets `delivered_at = now()`. Typically triggered by carrier webhook later.
- `MarkShipmentReturnedToSender(Shipment, string $reason)` — `InTransit → ReturnedToSender`. Reason logged.
- `MarkShipmentLost(Shipment, string $reason)` — any-non-terminal → `Lost`. Reason logged.

All transitions go through a private `UpdateShipmentStatus` mirroring `UpdateOrderStatus` for guard logic. `CreateShipmentForOrder` (existing) is renamed `CreateShipment` and gains the `orderLines` parameter.

### Events

`ShipmentCreated`, `ShipmentReady`, `ShipmentDispatched`, `ShipmentDelivered`, `ShipmentReturnedToSender`, `ShipmentLost`. `ShipmentDispatched` is the "shipped with tracking" event consumers' transactional-email listeners attach to; `ShipmentDelivered` is the "delivered" notification trigger.

`ShipmentLogSubscriber` routes the events to the Shipping log channel, parallel to `PaymentLogSubscriber`. Each log entry carries its own timestamp, so transitions that don't have a denormalized column on the Shipment (`Pending → Ready`, `InTransit → ReturnedToSender`, `→ Lost`) remain auditable — querying "when was this Shipment marked Lost" is a log lookup rather than a column read. The `shipped_at` and `delivered_at` columns exist as denormalizations of the heavily-queried transitions (customer-facing UI, SLA reporting, `Order::isComplete()`); other states stay log-only by design.

### Auto-create Shipment on `OrderCreated`

A default listener creates one `Pending` Shipment per Order on `OrderCreated`, using the Order's snapshotted shipping method and all of its order lines. Single-shipment is the typical case and every consumer would otherwise write the same listener.

Opt out via config (`shipping.auto_create_shipment` default `true`) for consumers that want to compose multiple Shipments at order-acceptance time (e.g. split warehouse routing, mixed in-stock/back-order). Opted-in consumers can still call `CreateShipment` again to add further Shipments — the auto-create listener creates the first; nothing prevents additions.

`Pending` is correct for every newly-confirmed order — the goods aren't packed yet, the label isn't generated, the carrier hasn't been told. State advances explicitly via `MarkShipmentReady` / `DispatchShipment`.

### Order completion semantics

`OrderStatus` no longer carries `Delivered`. Order completion is a derived accessor:

```php
public function isComplete(): bool
{
    return $this->status === OrderStatus::Confirmed
        && $this->shipments->isNotEmpty()
        && $this->shipments->every(
            fn (Shipment $s) => $s->status === ShipmentStatus::Delivered
        )
        && $this->paymentStatus() === PaymentStatus::Succeeded;
}
```

No `completed_at` column on Order — the data is already there. `delivered_at` on each Shipment plus the Payment-domain capture timestamp give "when did this complete?" answers without denormalization.

For admin UI that wants a single status badge ("Open / In transit / Complete / Cancelled"), expose a computed `displayStatus()` on Order that maps the union of `OrderStatus` + `paymentStatus()` + aggregated `ShipmentStatus` to a presentation enum. Three independent state machines internally, one summary string for humans.

## Out of scope (defer)

### Customer-initiated returns (RMA flow)

Customer says "I want to return this", system issues a return label, customer ships back, package receives. This is a separate Shipment going inbound. Two design questions to settle when the RMA flow is built (separately tracked in the consumer's commerce-ops TODO):

- Do return Shipments share the `shipments` table with a `direction` flag (`outbound` / `inbound`) and a separate state set, or a separate `ReturnShipment` model?
- Where does the return-policy lifecycle live (request → approved → label-issued → received → refunded)? Probably a sibling `Return` model owning that flow, with `inbound` Shipments being its instrument.

The current design doesn't preclude either approach.

### Multi-warehouse / origin selection

`origin_id` on Shipment + warehouse routing. Out of catchment for this package; revisit if a real multi-location operation appears.

### Carrier webhook ingestion

`MarkShipmentDelivered` is the obvious target for carrier webhook callers, but the package ships with manual status updates only. A `Carrier` sub-concept with webhook ingestion is a follow-up. The state machine is the prerequisite; webhook plumbing is bolt-on.

## Breaking changes summary

- `OrderStatus` loses `Processing`, `Shipped`, `Delivered`, `Refunded`. Consumers reading these values must migrate to `ShipmentStatus` and `PaymentStatus`.
- `HasShipment::shipment()` becomes `shipments()` (singular → plural). Call sites must update.
- `CreateShipmentForOrder` action renames to `CreateShipment`.
- New columns on `shipments` (status, carrier, tracking_number, tracking_url, shipped_at, delivered_at) — additive, no data loss.
- New `shipment_items` table — additive, requires backfill if any shipments exist (none in production at design time).

Per the package's "single-release-window is fine" stance with a single pre-launch consumer, these changes ship together.

## Connection to consumer commerce-ops items

Items in `in-other-worlds/TODO.md` § Commerce operations that fold into this design:

- "Tracking number on Order" — moves from project-side column add to package Shipment field. Project work changes to "render the Shipment-derived tracking on `/orders/{id}` and the shipped email".
- "Order lifecycle emails (shipped/delivered)" — listener attaches to `ShipmentDispatched`/`ShipmentDelivered`, not `OrderStatusChanged`.
- "Customer-facing returns/RMA workflow" — uses inbound Shipments once the RMA design lands.
- "Pre-fulfillment customer-initiated cancellation" — unchanged (operates on `OrderStatus`, before any Shipment exists).
