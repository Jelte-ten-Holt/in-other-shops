# FlowChain Domain

Orchestrates multi-step business processes (checkout, fulfillment, refunds) as linear, transactional pipelines. For fire-and-forget side effects (analytics, notifications), use regular Laravel events instead.

---

## Core Concepts

A **FlowChain** is a table of contents — the chain definition shows the full process at a glance. Each step is a chapter heading; the domain actions inside do the actual work.

```php
FlowChain::make()
    ->name('checkout')
    ->wrapInTransaction()
    ->step(ValidateCart::class)
    ->step(CalculatePricing::class)
    ->step(CreateOrder::class)
    ->step(AttachAddresses::class)
    ->step(ClearCart::class)
    ->run($payload);
```

### Payload

A DTO implementing `FlowPayload`. Constructor params are required inputs; nullable public props are step outputs. The payload flows through every step — each step reads what it needs and writes what it produces.

### Steps

Classes implementing `FlowStep`. Each step is a thin wrapper that pulls from the payload, calls a domain action, and writes results back. Domain actions stay independently reusable outside of flows.

### Results

`FlowChainResult` contains the final status, the payload, and a list of `FlowChainStepResult` entries — one per registered step, including skipped ones. This makes the full execution trace inspectable.

---

## Conditional Steps

Use `when()` for steps that should only run based on runtime payload state:

```php
// Single step
->when(fn (CheckoutPayload $p) => $p->voucherCode !== null, ApplyVoucher::class)

// Multiple steps under the same condition
->when(fn (CheckoutPayload $p) => $p->requiresShipping, fn (FlowChainBuilder $b) => $b
    ->step(CalculateShipping::class)
    ->step(ReserveInventory::class)
)
```

Skipped steps appear in the result with `FlowChainStepStatus::Skipped` so the table of contents remains complete.

Conditions inside a group compose with the parent: if the parent condition is false, all grouped steps are skipped regardless of inner conditions.

---

## Design Stance: Linear Pipelines Only

**FlowChain is deliberately a linear pipeline with optional steps. It does not support branching (if/else), loops, or goto-style jumps.**

This is a conscious design choice, not a missing feature. Here's why:

### What FlowChain provides
- `step()` — always runs
- `when()` — runs if the condition is true, skips otherwise
- `wrapInTransaction()` — the entire chain runs in a DB transaction

### What FlowChain does not provide
- **If/else branching** — no `otherwise()` or `else()` method
- **Runtime flow control** — no way for a step to skip subsequent steps or jump ahead
- **Sub-chain orchestration** — no built-in parent/child chain relationship

### How to handle these cases

**"Do X or Y based on a condition"** — use two `when()` calls with opposite conditions. It's slightly redundant but explicit:

```php
->when(fn ($p) => $p->isPhysical, CalculateShipping::class)
->when(fn ($p) => !$p->isPhysical, SendDownloadLink::class)
```

**"The flow diverges significantly based on a condition"** — use separate chains. If physical and digital product checkouts share only 2 steps out of 8, they're two different processes. A parent action picks which chain to run:

```php
final class PerformCheckout
{
    public function __invoke(CheckoutPayload $payload): FlowChainResult
    {
        return $payload->requiresShipping
            ? (new PhysicalCheckoutChain)($payload)
            : (new DigitalCheckoutChain)($payload);
    }
}
```

**"A step needs to trigger another process"** — see [Child Chains](#child-chains-unresolved) below. This is an open design question with two candidate approaches.

**"I want to build the chain differently based on configuration"** — build it conditionally. This is a valid pattern:

```php
$builder = FlowChain::make()->name('checkout')->step(ValidateCart::class);

if ($config->requiresApproval) {
    $builder->step(RequestApproval::class);
}

$builder->step(CreateOrder::class)->run($payload);
```

The chain is still linear at runtime. You're just deciding at build time which steps to include.

### Why no branching?

The moment a chain supports if/else, you're no longer reading a table of contents — you're reading a flowchart. Flowcharts are harder to reason about, harder to test, and harder to debug. Every branch doubles the number of paths through the code.

If your process genuinely has branches, it's likely two (or more) separate processes that happen to share some steps. Extract the shared steps into reusable domain actions, and define each process as its own chain. Each chain stays linear and readable.

---

## Child Chains (Unresolved)

A parent chain often needs to run a child chain — checkout triggers payment, fulfillment triggers shipping, refunds trigger restock. The child chain is a genuinely separate process with its own payload, but the parent depends on its result.

**The core problem is payload mapping.** A `CheckoutPayload` is not a `PaymentPayload`. Someone has to transform one into the other and write results back. Everything else (failure propagation, transaction scoping, result nesting) is mechanical once that's answered.

### The two gaps today

Running a sub-chain inside a step's `handle()` method technically works, but has two gaps:

1. **Failure propagation is manual.** The sub-chain returns `FlowChainResult` — it doesn't throw. The step must check and throw explicitly, which is easy to forget.
2. **Transaction nesting is implicit.** The sub-chain runs inside the parent's DB transaction with no explicit control over scoping.

### Option B: `RunsChildChain` trait

A trait that provides `runOrFail()` — failure propagation handled, payload mapping stays in the step:

```php
final class ProcessPayment implements FlowStep
{
    use RunsChildChain;

    public function handle(FlowPayload $payload): void
    {
        assert($payload instanceof CheckoutPayload);

        $result = $this->runOrFail(
            PaymentChain::for($payload->order, $payload->total)
        );

        $payload->paymentId = $result->payload->paymentId;
    }
}
```

**Pros:** Simple, no new concepts. Chains stay fully independent. Mapping is explicit.
**Cons:** Child chain is opaque in parent result — you see "ProcessPayment: Completed", not the individual payment steps. Transaction scoping still implicit. Debugging a failed child chain means looking at logs/storage, not the parent result.

### Option C: First-class `chain()` with a `ChainBridge` contract

A bridge class handles mapping. The framework handles failure propagation, result nesting, and transaction scoping:

```php
// Chain definition — child chain is visible in the table of contents
->step(CreateOrder::class)
->chain(PaymentBridge::class)
->step(ClearCart::class)
```

```php
final class PaymentBridge implements ChainBridge
{
    public function build(FlowPayload $parent): FlowChainBuilder { /* ... */ }
    public function mapPayload(FlowPayload $parent): FlowPayload { /* ... */ }
    public function onSuccess(FlowPayload $parent, FlowPayload $child): void { /* ... */ }
}
```

**Pros:** Child steps appear in parent result (nested). Failure propagation automatic. Transaction scoping can be explicit (`->chain(PaymentBridge::class)->shareTransaction()`). Full visibility for debugging.
**Cons:** New concept (`ChainBridge`), more ceremony per integration. Bridge classes are one more thing to test.

### Decision criteria

The key differentiator is **result visibility**. If debugging production failures requires seeing child chain steps in the parent result, Option C is worth the ceremony. If child chains are rare and logging the child result separately is acceptable, Option B is simpler and sufficient.

### Expected child chain usage in this project

| Parent Chain | Child Chain | Why it's separate |
|---|---|---|
| Checkout | Payment | Payment is gateway-specific, reused for subscription renewals and manual charges |
| Checkout | Fulfillment | Only for physical products; digital products skip it entirely |
| Fulfillment | Shipping | Third-party API integration, may run async |
| Refund | Payment reversal | Reuses payment gateway in reverse |
| Refund | Restock | Inventory domain, independent of payment |
| Subscription renewal | Payment | Same payment chain, different parent |
| Subscription renewal | Fulfillment | Same fulfillment chain, different parent |

That's 7 known integrations across 4 parent chains. Assuming we miss roughly half of the real-world cases, that's ~10-14 child chain integrations.

At that volume, the ceremony of Option C (one bridge class per integration) pays for itself — these aren't edge cases, they're the core business logic. And every one of them is a potential production failure that someone will need to debug. "Which payment step failed during checkout?" is a question you want answered by the result object, not by digging through logs.

**Recommendation: Option C.** The expected frequency justifies the ceremony, and result visibility is too valuable for production debugging to give up.

Decision is deferred until the Payment domain is built, when the first real child chain integration will prove or disprove this analysis.

---

## Transaction Support

`wrapInTransaction()` wraps the entire chain execution in a database transaction. If any step throws, everything rolls back — no orphaned orders, no partial state.

---

## Events

FlowChain dispatches lifecycle events unless suppressed with `withoutEvents()`:

| Event | When |
|---|---|
| `FlowChainStarted` | Before the first step |
| `FlowChainStepCompleted` | After each successful step |
| `FlowChainStepFailed` | When a step throws |
| `FlowChainCompleted` | After all steps succeed |
| `FlowChainFailed` | When the chain stops due to a step failure |

Skipped steps (via `when()`) do not fire events — they didn't execute.

---

## Contracts

| Contract | Purpose |
|---|---|
| `FlowPayload` | Marker interface for payload DTOs |
| `FlowStep` | Single method: `handle(FlowPayload $payload): void` |

---

## Writing Steps

Steps should be thin wrappers. They pull from the payload, call a domain action, and write results back:

```php
final class CalculatePricing implements FlowStep
{
    public function __construct(
        private readonly CalculateTotal $calculateTotal,
    ) {}

    public function handle(FlowPayload $payload): void
    {
        assert($payload instanceof CheckoutPayload);

        $items = $this->transformCartItems($payload);

        $payload->priceBreakdown = ($this->calculateTotal)(
            items: $items,
            currency: $payload->cart->currency,
        );
    }
}
```

Domain actions (`CalculateTotal`) stay independently usable outside of flows. The step is just the glue.

---

## Upgradability: Keep Steps Thin, Keep the Connection Narrow

FlowChain's upgrade story depends on one rule: **steps must only interact with domains through their public contracts (interfaces and action signatures), never through internal implementation details.**

### Why this matters

E-commerce platforms face four competing dimensions: upgradability, modifiability, readability, and overhead.

- **Shopware** fires events before/after every granular operation. Maximum hookability, but the actual flow is invisible — scattered across dozens of event listeners. Readability is sacrificed.
- **Magento** favors configuration and abstraction layers. Powerful, but the overhead of understanding and working within the system is enormous.
- **FlowChain** makes the chain definition the readable table of contents. Steps are swappable, and new steps slot in between existing ones. But this only works for upgrades if the connection between steps and domains is narrow enough that domain internals can change without breaking steps.

### The contract is the boundary

A step should depend on:
- Domain **contracts** (`HasPrices`, `HasOrders`, `HasCart`)
- Domain **action signatures** (`CalculateTotal::__invoke(array $items, Currency $currency, ...): PriceBreakdown`)
- Domain **DTOs** (`PriceBreakdown`, `PriceBreakdownLine`)

A step should **never** depend on:
- Model column names or schema details
- Query builder calls on domain models (`$order->lines()->where(...)`)
- Internal domain methods not on a contract

```php
// Good — calls a domain action through its public signature
$payload->priceBreakdown = ($this->calculateTotal)(
    items: $items,
    currency: $payload->cart->currency,
);

// Bad — reaches into domain internals, coupled to schema
$total = $order->lines()->sum('line_total');
$order->update(['total' => $total - $discount]);
```

### How this enables upgrades

When a domain package upgrades:
- **Action internals change** (new pricing algorithm, different tax calculation) → steps don't care, they call the same action signature.
- **New action parameters added** (with defaults) → existing steps continue to work, new steps can use the new parameters.
- **Action signature changes** (breaking) → only the thin step wrapper needs updating, not the business logic.

When a project adds custom steps:
- "I need X between A and B" → add a new step class. Existing steps are untouched. Package upgrades don't conflict.
- "I need different behavior for step A" → replace the step in the chain definition. The custom step calls the same domain actions, just orchestrates them differently.

### The test

Before writing a step, ask: *"If the domain package released a new major version that changed its internal schema but kept its action signatures stable, would this step still work?"* If not, you're coupled too tightly.
