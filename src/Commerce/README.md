# Commerce Domain

Covers the cart-to-order lifecycle: collecting items, creating orders, and tracking their status. Cart, Customer, and Order live as sub-namespaces within a single domain because they're tightly coupled — a customer owns a cart that becomes an order at checkout.

If the sub-concerns ever need to be extracted independently, the sub-namespace structure makes splitting straightforward: move each to its own domain and introduce contracts at the boundaries.

---

## Cart (`Commerce/Cart/`)

Session/token-based shopping cart, designed for guest checkout with optional customer linking.

### Models

- **Cart** — identified by `session_token` (guests) or polymorphic `owner` (e.g., authenticated user). Has many `CartItem`s. Stores `currency`.
- **CartItem** — belongs to a Cart, polymorphic `cartable` relationship (e.g., a Product, Bundle, or any model implementing `Cartable`). Stores quantity; price is resolved at read time via the Pricing domain.

### Contracts

- **`Cartable`** — any model that can be added to a cart. Requires `getCartableLabel(): string` and `getCartableDescription(): ?string`.

### Concerns

- **`InteractsWithCart`** — trait for models implementing `Cartable`. Provides `cartItems(): MorphMany` and default `getCartableLabel()`/`getCartableDescription()` that return the model's `name`/`description` columns.

### Actions

- `ResolveCart` — find or create a cart for the given owner (precedence) or session token
- `AddToCart` — add a cartable item (or increment quantity if already present); dispatches `CartUpdated`
- `UpdateCartItemQuantity` — change quantity; setting `0` removes the item; dispatches `CartUpdated`
- `RemoveFromCart` — remove a line item; dispatches `CartUpdated`
- `ClearCart` — empty the cart; dispatches `CartCleared`
- `ClaimCart` — transfer a guest cart to an authenticated owner; dispatches `CartClaimed`

### Events

- `CartUpdated` — dispatched on add/update/remove
- `CartCleared` — dispatched when the cart is emptied
- `CartClaimed` — dispatched when a guest cart is claimed by an authenticated owner

### REST API

The package ships an opt-in REST layer under `InOtherShops\Commerce\Cart\Http\*`. `CommerceServiceProvider` auto-registers the routes when `commerce.cart.api.enabled` is true (default). Defaults: prefix `api/cart`, middleware `['web']` (session needed for guest token resolution), default currency `EUR`. Override per-consumer in `config/commerce.php`.

| Verb | Path | Action |
|---|---|---|
| GET | `/api/cart` | Show current cart |
| DELETE | `/api/cart` | Clear all items |
| POST | `/api/cart/items` | Add an item — body: `{type, id, quantity?}` |
| PATCH | `/api/cart/items/{item}` | Update quantity — body: `{quantity}` (0 removes) |
| DELETE | `/api/cart/items/{item}` | Remove an item |

`type` is the morph-map alias of the cartable. Validation rejects types that don't exist in the morph map or whose model doesn't implement `Cartable`. Items not belonging to the current cart return 404 from update/destroy.

Owner resolution uses Laravel defaults: `Auth::user()` when authenticated, `session()->getId()` otherwise. Consumers driving the cart in-process (e.g., Livewire) can disable the API via `commerce.cart.api.enabled = false`.

---

## Customer (`Commerce/Customer/`)

Customer records for the shop. A customer can optionally be linked to an authenticatable user (via polymorphic `authenticatable` relationship) and owns orders and addresses (via the Location domain).

### Models

- **Customer** — name, email, phone, optional `authenticatable` morph. Uses `InteractsWithAddresses` from Location. Optionally belongs to a `CustomerGroup`.
- **CustomerGroup** — name, code (unique slug). Groups customers for segmentation (e.g., "Wholesale", "VIP", "Retail"). Referenced by Pricing Rules (future) to resolve group-specific prices/discounts.

### Contracts

- **`HasCustomer`** — any authenticatable model (e.g., `User`) that can be linked to a `Customer`. Requires a `customer(): MorphOne` relationship.

### Concerns

- **`InteractsWithCustomers`** — trait for models implementing `HasCustomer`, providing the `customer()` MorphOne relationship via the Commerce registry.

### Relationships

- `orders()` — `HasMany` to `Order`
- `group()` — `BelongsTo` to `CustomerGroup`
- `addresses()` — polymorphic via Location domain
- `authenticatable()` — `MorphTo` (links to a User or any auth model)

### Linking Authenticatable Models

Any model that represents an authenticated user can be linked to a Customer record:

```php
use InOtherShops\Commerce\Customer\Contracts\HasCustomer;
use InOtherShops\Commerce\Customer\Concerns\InteractsWithCustomers;

class User extends Authenticatable implements HasCustomer
{
    use InteractsWithCustomers; // gives $user->customer()
}
```

The `authenticatable` morph columns on the `customers` table are nullable — a Customer can exist without a linked user (guest checkout). The link is established during checkout when the user is authenticated.

---

## Order (`Commerce/Order/`)

Represents completed purchases. Orders snapshot all pricing and product data at the time of creation so they remain accurate even if catalog data changes later.

### Planned Models

- **Order** — top-level order record. Tracks status, totals, and references to shipping/billing addresses (via Location domain). Polymorphic `orderable` support allows different contexts (e.g., subscription orders vs. one-off purchases).
- **OrderLine** — belongs to an Order. Snapshots the item name, price, quantity, and a polymorphic reference back to the original `orderable` model for traceability.

### Contracts

- **`Orderable`** — any model that can become an order line (provides snapshot data: name, unit price, metadata).

### Concerns

- **`InteractsWithOrders`** — trait for models implementing `Orderable`, providing convenience methods like `orderLines()`.

### Enums

- **`OrderStatus`** — pending, confirmed, processing, shipped, delivered, cancelled, refunded.

### Actions

- **`UpdateOrderStatus`** — transitions an order to a new status. Validates the transition against `OrderStatus::allowedTransitions()` (throws `InvalidArgumentException` if invalid). Dispatches `OrderStatusChanged` event on success.

### Status Transitions

`OrderStatus` defines which transitions are valid via `allowedTransitions()` and `canTransitionTo()`:

```
Pending    → Confirmed, Cancelled
Confirmed  → Processing, Cancelled, Refunded
Processing → Shipped, Cancelled, Refunded
Shipped    → Delivered, Refunded
Delivered  → Refunded
Cancelled  → (terminal)
Refunded   → (terminal)
```

All status changes should go through `UpdateOrderStatus` to ensure transition validation and event dispatch.

### Events

- **`OrderCreated`** — dispatched when an order is successfully created. Carries the `Order` model. Dispatched from project-level `PerformCheckout` action (not from the domain itself, since order creation is an orchestration concern).
- **`OrderFailed`** — dispatched when checkout fails. Carries the failure `reason` (string) and `failedStep` (nullable string). No `Order` model — one was never created.
- **`OrderStatusChanged`** — dispatched by `UpdateOrderStatus` on every successful status transition. Carries `Order`, `from` (OrderStatus), and `to` (OrderStatus).

---

## Making a Model Orderable

Any model that can become an order line needs three things:

### 1. Implement `Orderable` and use `InteractsWithOrders`

```php
use InOtherShops\Commerce\Order\Contracts\Orderable;
use InOtherShops\Commerce\Order\Concerns\InteractsWithOrders;

final class Product extends Model implements Orderable
{
    use InteractsWithOrders;  // gives $product->orderLines()

    public function toOrderLineData(string $currencyCode): array
    {
        // Resolve price using whatever pricing system you have
        $price = $this->priceFor(Currency::from($currencyCode));

        return [
            'description' => $this->name,
            'sku'         => $this->sku,
            'currency'    => $currencyCode,
            'unit_price'  => $price?->amount ?? 0,
        ];
    }

    public function availableCurrencies(): array
    {
        // Return ISO 4217 codes this model has prices for
        return $this->priceCurrencies();
    }
}
```

### 2. Register a morph map alias

In your service provider (or `AppServiceProvider`), register the model so the `orderable_type` column stores a short alias instead of the full class name:

```php
Relation::morphMap([
    'product' => Product::class,
]);
```

### 3. Use it at checkout time

The checkout action supplies the quantity and computes the line total:

```php
$data = $product->toOrderLineData('EUR');

$order->lines()->create([
    ...$data,
    'orderable_type' => 'product',
    'orderable_id'   => $product->id,
    'quantity'        => $quantity,
    'line_total'      => $data['unit_price'] * $quantity,
]);
```

### What `Orderable` provides

| Piece | Purpose |
|---|---|
| `Orderable` contract | Defines `toOrderLineData(string $currencyCode): array` — snapshots catalog data |
| `Orderable` contract | Defines `availableCurrencies(): array` — returns ISO 4217 codes this model can be ordered in |
| `InteractsWithOrders` trait | Adds `orderLines(): MorphMany` — reverse lookup ("which orders contain this product?") |

The contract intentionally does **not** include `quantity` or `line_total` — those are checkout-time values, not catalog data.

### Using `Purchasable` (project-level convenience)

The project's `Purchasable` contract composes `HasPrices` + `Orderable`, so implementing `Purchasable` covers both pricing and order snapshot capabilities in one interface:

```php
interface Purchasable extends HasPrices, Orderable {}
```

---

## Integration Points

- **Pricing** — cart reads prices from the Pricing domain at display/checkout time. Prices are snapshotted into OrderLines at order creation.
- **Location** — shipping and billing addresses are attached to Orders via the Location domain's polymorphic relationship.
- **Payment** — after an Order is created, the Payment domain handles charging. The Order is the `payable` model.
- **Customer** — lives within Commerce as a subdomain. Owns orders and addresses.
- **Inventory** — Stock is reserved during checkout (`ReserveStockForOrder` step) and confirmed on payment success (`ConfirmReservationOnPayment` listener). Expired reservations are released by the scheduled `inventory:release-expired` command.
- **Shipping** — Order implements `HasShipment`. A `Shipment` record is created during checkout with the calculated shipping cost. Shipping cost is included in the order total.
