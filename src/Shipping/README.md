# Shipping Domain

Shipping cost calculation and shipment tracking for orders. Attaches to any model via polymorphic `morphOne` relationship.

## Architecture

### Config-defined zones and methods

Zones (groups of countries with shared pricing) and methods (carriers/services with per-zone rates) are defined in the consuming project's `config/shipping.php`. This is intentional: zone definitions and rate cards change rarely and benefit from being version-controlled alongside code, so no admin UI is shipped.

A country belongs to exactly one zone. A country not listed in any zone is unshippable.

```php
// config/shipping.php
return [
    'models' => [
        'shipment' => Shipment::class,
    ],

    'zones' => [
        'de' => [
            'name' => 'Germany',
            'currency' => 'EUR',
            'countries' => ['DE'],
            'free_shipping_threshold' => 5000, // €50.00, null to disable
            'sort_order' => 0,
        ],
        'eu' => [
            'name' => 'European Union',
            'currency' => 'EUR',
            'countries' => ['AT', 'BE', 'NL', /* ... */],
            'free_shipping_threshold' => 10000,
            'sort_order' => 10,
        ],
    ],

    'methods' => [
        'standard' => [
            'name' => 'Standard shipping',
            'sort_order' => 0,
            'is_active' => true,
            'rates' => [
                'de' => 595,
                'eu' => 1499,
            ],
        ],
        'express' => [
            'name' => 'Express shipping',
            'sort_order' => 10,
            'is_active' => true,
            'rates' => [
                'de' => 999,
                // omitted = unavailable in EU
            ],
        ],
    ],
];
```

### Shipment model

Stores the shipping cost and method snapshot for each shippable model. Uses a polymorphic `shippable` morph to attach to any model (typically Order).

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `shippable_type` | string | morph type |
| `shippable_id` | bigint | morph ID |
| `cost` | integer | shipping cost in cents |
| `currency` | string(3) | ISO 4217 currency |
| `method` | string, nullable | method identifier snapshot |
| `timestamps` | | |

### Value objects

`ShippingZone` and `ShippingMethod` are `final readonly` DTOs built from config — not Eloquent models. Resolve them via `ShippingConfig`.

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
    public function shipment(): MorphOne;
}
```

`InteractsWithShipment` trait implements the `shipment()` relationship using the `Shipping::shipment()` registry.

### Actions

- **`ResolveShippingZoneForCountry(string $countryCode): ?ShippingZone`** — looks up which zone a country belongs to. Returns `null` if the country isn't shipped to.
- **`ResolveShippingZoneForAddress(Address $address): ?ShippingZone`** — convenience wrapper around the country resolver.
- **`ListAvailableShippingMethods(?ShippingZone $zone = null): array<ShippingMethod>`** — active methods, sorted. When a zone is provided, methods without a rate for that zone are filtered out.
- **`CalculateShippingCost(ShippingMethod $method, ShippingZone $zone, ?int $subtotalCents = null): int`** — returns the rate in cents. Throws `MethodNotAvailableInZoneException` if the method has no rate for the zone. Returns `0` when `subtotalCents` is provided and meets/exceeds the zone's free-shipping threshold.
- **`CreateShipmentForOrder(Model $order, ShippingMethod $method, ShippingZone $zone, int $cost): Shipment`** — persists the shipment record with a cost snapshot supplied by the caller (so the caller controls free-shipping logic, not this action).

### Exceptions

All extend `ShippingException` (which extends `\DomainException`):

- **`CountryNotShippableException`** — thrown by callers when a resolver returns `null` and the caller wants to surface a typed failure.
- **`MethodNotAvailableInZoneException`** — thrown by `CalculateShippingCost` when the chosen method has no rate in the resolved zone.

## Wiring Into a Model

### 1. Implement `HasShipment` and use `InteractsWithShipment`

```php
use InOtherShops\Shipping\Contracts\HasShipment;
use InOtherShops\Shipping\Concerns\InteractsWithShipment;

class Order extends Model implements HasShipment
{
    use InteractsWithShipment; // gives $order->shipment()
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

$cost = (new CalculateShippingCost)($method, $zone, subtotalCents: $cart->subtotal());

(new CreateShipmentForOrder)($order, $method, $zone, $cost);
```

## Dependencies

- `Currency` — for the `Currency` enum used in zone definitions and shipment storage.
- `Location` — for the `Address` model accepted by `ResolveShippingZoneForAddress`.

## Future

- Weight/dimension-based rate brackets (extension point: `CalculateShippingCost` already accepts the cart context via subtotal — adding weight is additive)
- Carrier API integration (DHL, DPD, etc.)
- Promotional / temporary free-shipping overrides via DB on top of config defaults
