# Shipping Domain

Shipping cost calculation, shipment lifecycle, and tracking. Attaches to any model via polymorphic `morphMany` relationship.

## Architecture

### Three independent state machines

The Shipping domain owns one of the three independent state machines that describe an Order's life — alongside `OrderStatus` (Pending / Confirmed / Cancelled) in Commerce and `PaymentStatus` in Payment. See `docs/shipment-lifecycle-design.md` for the design rationale.

`ShipmentStatus`:

```
Pending → Ready → InTransit → Delivered     (terminal)
                            → ReturnedToSender → Pending (reship)
                                              → Lost     (terminal)
                            → Lost            (terminal)
Pending / Ready             → Lost
```

### Config-defined zones, methods, and carriers

Zones (groups of countries with shared pricing), methods (carriers/services with per-zone rates), and carriers (display name + tracking-URL template) are defined in the consuming project's `config/shipping.php`. This is intentional: rate cards and carrier templates change rarely and benefit from being version-controlled alongside code.

A country belongs to exactly one zone. A country not listed in any zone is unshippable.

```php
// config/shipping.php
return [
    'models' => [
        'shipment' => Shipment::class,
        'shipment_item' => ShipmentItem::class,
    ],

    'auto_create_shipment' => true,

    'zones' => [
        'de' => [
            'name' => 'Germany',
            'currency' => 'EUR',
            'countries' => ['DE'],
            'free_shipping_threshold' => 5000, // €50.00, null to disable
            'sort_order' => 0,
        ],
    ],

    'methods' => [
        'standard' => [
            'name' => 'Standard shipping',
            'sort_order' => 0,
            'is_active' => true,
            'rates' => ['de' => 595],
        ],
    ],

    'carriers' => [
        'dhl' => [
            'name' => 'DHL',
            'tracking_url_template' => 'https://nolp.dhl.de/nextt-online-public/en/search?piececode={tracking_number}',
        ],
    ],
];
```

`carrier` is a free-form string on Shipment — the config map provides validation hints and tracking-URL templates but doesn't enforce. One-off carriers with non-templatable URLs (signed query strings, customer-token-bearing URLs) work too: pass an explicit `$trackingUrl` to `DispatchShipment` and the template lookup is skipped.

**Tracking is optional.** Untracked post is a real service — often the cheaper of a shop's two methods for a small parcel — and it has no tracking number by definition. `DispatchShipment` therefore takes carrier and number as nullable, and stamps `shipped_at` and dispatches `ShipmentDispatched` **regardless**. This matters more than it looks: `InTransit` is reachable only through that action and `Delivered` only through `InTransit`, so requiring a number would strand every untracked parcel at `Ready` and silently disable everything downstream of dispatch. A shop that genuinely wants to insist on a number enforces it in its own admin form.

### Models

**Shipment** — operational artifact for a parcel. Owns its lifecycle state, carrier, tracking, and timestamps.

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `shippable_type` / `shippable_id` | morph | owner (typically Order) |
| `status` | string | `ShipmentStatus` |
| `method` | string, nullable | shipping method identifier snapshot |
| `carrier` | string, nullable | carrier identifier (config key or free-form) |
| `tracking_number` | string, nullable | |
| `tracking_url` | string, nullable | derived from carrier template at dispatch when not given |
| `shipped_at` | timestamp, nullable | set on `InTransit` transition |
| `delivered_at` | timestamp, nullable | set on `Delivered` transition |
| `timestamps` | | |

Customer-facing shipping cost lives on the **Order** snapshot (`shipping_cost` / `shipping_cost_currency`), not on Shipment — Shipment is the operational artifact, Order is the financial one.

**ShipmentItem** — `(shipment_id, order_line_id, quantity)`. Lets a Shipment cover a subset of the Order's lines (split shipments, partial fulfillment).

### Value objects

`ShippingZone` and `ShippingMethod` are `final readonly` DTOs built from config. Resolve via `ShippingConfig`.

```php
ShippingConfig::zones();              // array<string, ShippingZone>
ShippingConfig::zone('de');           // ?ShippingZone
ShippingConfig::zoneByCountry('NL');  // ?ShippingZone (one country = one zone)
ShippingConfig::methods();            // array<string, ShippingMethod>
ShippingConfig::method('standard');   // ?ShippingMethod
```

### Contract & Trait

```php
interface HasShipment
{
    public function shipments(): MorphMany;
}
```

`InteractsWithShipment` trait implements `shipments()` using `Shipping::shipment()`. Convenience accessors for the single-shipment-typical case (`shipments()->latest()->first()`) live in the consumer.

### Actions

- **`ResolveShippingZoneForCountry(string $countryCode): ?ShippingZone`** — looks up which zone a country belongs to. Returns `null` if the country isn't shipped to.
- **`ResolveShippingZoneForAddress(Address $address): ?ShippingZone`** — convenience wrapper around the country resolver.
- **`ListAvailableShippingMethods(?ShippingZone $zone = null): array<ShippingMethod>`** — active methods, sorted. When a zone is provided, methods without a rate for that zone are filtered out.
- **`CalculateShippingCost(ShippingMethod $method, ShippingZone $zone, ?int $subtotalCents = null): int`** — returns the rate in cents. Throws `MethodNotAvailableInZoneException` if the method has no rate for the zone. Returns `0` when `subtotalCents` is provided and meets/exceeds the zone's free-shipping threshold.
- **`CreateShipment(Model $order, ShippingMethod $method, ?Collection $orderLines = null): Shipment`** — creates a `Pending` Shipment with one `ShipmentItem` per included `OrderLine`. `orderLines` defaults to all of the order's lines. Dispatches `ShipmentCreated`.
- **`MarkShipmentReady(Shipment): Shipment`** — `Pending → Ready`. Dispatches `ShipmentReady`.
- **`DispatchShipment(Shipment, ?string $trackingNumber = null, ?string $carrier = null, ?string $trackingUrl = null): Shipment`** — `Ready → InTransit`, sets `shipped_at`, persists tracking. Dispatches `ShipmentDispatched`. **All tracking is optional** — untracked post still ships and still announces; see the note under Configuration. No URL is derived when either the carrier or the number is absent (`''` counts as absent).
- **`MarkShipmentDelivered(Shipment): Shipment`** — `InTransit → Delivered`, sets `delivered_at`. Dispatches `ShipmentDelivered`. Typical webhook target.
- **`MarkShipmentReturnedToSender(Shipment, string $reason): Shipment`** — `InTransit → ReturnedToSender`. Reason is logged.
- **`MarkShipmentLost(Shipment, string $reason): Shipment`** — any non-terminal → `Lost`. Reason is logged.

`UpdateShipmentStatus` is the shared internal guard — call the typed actions, not it.

### Events

`ShipmentCreated`, `ShipmentReady`, `ShipmentDispatched`, `ShipmentDelivered`, `ShipmentReturnedToSender`, `ShipmentLost`. `ShipmentDispatched` is the "it has been posted" event consumers' transactional-email listeners attach to — it fires with or without tracking, so a listener must treat `tracking_number` / `tracking_url` as nullable rather than assuming a parcel that shipped can be tracked. `ShipmentDelivered` is the "delivered" notification trigger.

### Auto-create on `OrderCreated`

A default listener (`Commerce\Order\Listeners\CreateShipmentForNewOrder`, lives in Commerce because Commerce → Shipping is the legitimate dependency direction) creates one `Pending` Shipment per Order on `OrderCreated`, using the Order's snapshotted shipping method and all of its lines.

Disable via `config('shipping.auto_create_shipment')` for consumers that compose multiple Shipments at order-acceptance time (e.g. split warehouse routing). Opted-in consumers can still call `CreateShipment` again to add further Shipments.

Orders without a shipping method (digital-goods-only) skip auto-creation. The listener also skips when `shipping.methods` is wholly empty — that means the consumer hasn't onboarded shipping at all, and a stray identifier shouldn't blow up. When `shipping.methods` is configured but the order's identifier doesn't resolve, the listener throws — that's true config drift and should surface.

### Order completion

`Order::isComplete()` is a derived accessor — there is no `completed_at` column. An order is complete when it is `Confirmed`, paid in full, has at least one Shipment, and every Shipment is `Delivered`.

### Exceptions

All extend `ShippingException` (which extends `\DomainException`):

- **`CountryNotShippableException`** — thrown by callers when a resolver returns `null` and the caller wants to surface a typed failure.
- **`MethodNotAvailableInZoneException`** — thrown by `CalculateShippingCost` when the chosen method has no rate in the resolved zone.
- **`InvalidShipmentStatusTransitionException`** — thrown by `UpdateShipmentStatus` (and therefore the typed actions) when a target state isn't reachable from the current one.

## Wiring Into a Model

### 1. Implement `HasShipment` and use `InteractsWithShipment`

```php
use InOtherShops\Shipping\Concerns\InteractsWithShipment;
use InOtherShops\Shipping\Contracts\HasShipment;

class Order extends Model implements HasShipment
{
    use InteractsWithShipment; // gives $order->shipments()
}
```

### 2. Register a morph map alias

```php
Relation::morphMap([
    'order' => Order::class,
]);
```

### 3. Compute and persist a shipment

```php
$zone = (new ResolveShippingZoneForCountry)($address->country_code)
    ?? throw CountryNotShippableException::forCountry($address->country_code);

$method = ShippingConfig::method('standard');

// Snapshot the cost on the Order (shipping_cost / shipping_cost_currency).
$cost = (new CalculateShippingCost)($method, $zone, subtotalCents: $cart->subtotal());

// Then either rely on the auto-create listener, or call:
(new CreateShipment)($order, $method);
```

### 4. Drive shipments through their lifecycle

```php
app(MarkShipmentReady::class)($shipment);
app(DispatchShipment::class)($shipment, trackingNumber: 'TRK123', carrier: 'dhl');
// …or untracked standard post, which still stamps shipped_at and fires the event:
app(DispatchShipment::class)($shipment);
app(MarkShipmentDelivered::class)($shipment);
```

## Dependencies

- `Currency` — for the `Currency` enum used in zone definitions.
- `Location` — for the `Address` model accepted by `ResolveShippingZoneForAddress`.
- `Logging` — `ShipmentLogSubscriber` routes events to the `shipping` log channel.

## Future

- Weight/dimension-based rate brackets (extension point: `CalculateShippingCost` already accepts cart context via subtotal — adding weight is additive).
- Carrier webhook ingestion (`MarkShipmentDelivered` is the obvious target; the state machine is the prerequisite).
- Customer-initiated returns (RMA) using inbound Shipments — separate design effort.
