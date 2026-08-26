# Tracking

Attribution: which surface drove a cart add, and which of those adds became
purchases. Two tables, two models, two FlowChain steps. Nothing else — Tracking
deliberately ships no read surface, no config beyond the model registry, and no
log subscriber.

## The pair

| Table | Written by | Lifetime |
| --- | --- | --- |
| `cart_item_attributions` | `RecordCartItemAttribution` (an `AddToCartChain` step) | Volatile — cascade-deletes with the cart item |
| `order_line_attributions` | `SnapshotCartItemAttributions` (a checkout step) | Durable — the record that survives the cart being cleared after payment |

One row per cart item and per order line respectively, enforced by a unique
key. **First-source-wins**: a re-add is a quantity change, not a new
conversion.

## Wiring it up

Nothing happens until a consumer inserts both steps. That IS the on/off switch
— there is no enable flag, because "did you wire the step" is a clearer answer
than "is the config true".

**1. Capture the source.** On a surface that knows what the shopper was
browsing (a filtered listing, a category page), pass it on the cart-add:

```json
{ "type": "product", "id": 12, "quantity": 1,
  "metadata": { "source": { "type": "category", "id": 3 } } }
```

`source.type` must be a morph alias registered in the consumer's morph map, and
`source.id` must be a positive integer. Anything else is silently skipped —
attribution must never cost a shopper their add-to-cart. Derive the source from
a **resolved model**, not a raw query parameter: a bogus `?category=nonsense`
should produce no attribution, not a row pointing at a category that does not
exist.

**2. Insert `RecordCartItemAttribution`** into the published `AddToCart` chain,
after `FindOrCreateCartItemStep` (it needs `payload->cartItem`).

**3. Implement `HasCheckoutAttribution`** on the checkout payload. Checkout
chains are app-owned in every consumer, so the package reaches the cart and
order through this contract rather than a concrete payload class. Two one-line
methods:

```php
public function attributionCart(): Cart { return $this->cart; }
public function attributionOrder(): ?Order { return $this->order; }
```

**4. Insert `SnapshotCartItemAttributions`** into the checkout chain, after the
step that creates the order and its lines, and before anything that mutates or
clears the cart.

## Two different failure policies, on purpose

`RecordCartItemAttribution` swallows everything — a malformed source writes no
row and the cart add proceeds. `SnapshotCartItemAttributions` lets write
failures bubble, because it runs inside the checkout transaction and a
half-written snapshot on a real order is worse than a rolled-back checkout the
shopper can retry. One is a cart add; the other is the money path.

## No log subscriber

Every other model-bearing domain ships a `{Domain}LogSubscriber`. This one does
not: an attribution row already IS the record, so logging "an attribution was
recorded" doubles a money-path write and gives an operator nothing to read.
First domain to skip the convention deliberately.

## Reading it back

Not the package's job. `order_line_attributions` is the table to query — group
by `source_type`/`source_id` and join to order lines for units and revenue.
Consumers own their own read surfaces (in-other-worlds exposes an MCP report
filtered to content sources; bianka ships a Filament widget for category/tag),
because what counts as an interesting source is a shop-specific question.
