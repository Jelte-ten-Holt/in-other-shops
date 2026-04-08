# Shipping Domain

Shipping cost calculation and shipment tracking for orders. Attaches to any model via polymorphic `morphOne` relationship.

## Architecture

### Flat-rate shipping (MVP)

The current implementation provides a single flat-rate shipping cost from config. The `CalculateShippingCost` action returns the configured amount. When real shipping methods are needed (weight-based, carrier APIs, zone pricing), this action becomes the extension point.

### Shipment model

Stores the shipping cost and method for each shippable model. Uses a polymorphic `shippable` morph to attach to any model (currently Order).

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `shippable_type` | string | morph type |
| `shippable_id` | bigint | morph ID |
| `cost` | integer | shipping cost in cents |
| `method` | string, nullable | shipping method identifier (future) |
| `timestamps` | | |

### Contract & Trait

```php
interface HasShipment
{
    public function shipment(): MorphOne;
}
```

`InteractsWithShipment` trait implements the `shipment()` relationship using the `Shipping::shipment()` registry.

### Action

- **`CalculateShippingCost`** — returns the flat-rate shipping cost from `config('shipping.flat_rate')`. Accepts no parameters currently. Extension point for address/weight/method-based calculation.

### Config

```php
return [
    'flat_rate' => env('SHIPPING_FLAT_RATE', 595), // cents
    'models' => [
        'shipment' => Shipment::class,
    ],
];
```

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

In your service provider, register the model so `shippable_type` stores a short alias:

```php
Relation::morphMap([
    'order' => Order::class,
]);
```

### 3. Create a shipment

```php
$order->shipment()->create([
    'cost' => app(CalculateShippingCost::class)(),
    'method' => null, // future: shipping method identifier
]);
```

## Dependencies

None. Independent domain.

## Future

- Shipping method selection (standard, express, pickup)
- Weight/dimension-based calculation
- Carrier API integration (PostNL, DHL, etc.)
- Free shipping thresholds
- Shipping zone pricing
