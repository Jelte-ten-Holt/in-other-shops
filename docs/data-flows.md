# Data Flows — in-other-shops

This package contributes periphery to every consumer that installs it: auto-scheduled commands, event listeners, observers, subscribers, model boot hooks, and the events it dispatches. **This doc is the authoritative list of what this package adds to a consumer's runtime.**

Consumers reference this file from their own `docs/data-flows.md` rather than re-deriving it. The version that applies is whatever is pinned in their `composer.lock` — bump the package and the new version of this doc applies.

**Definition of done:** any change to a `*ServiceProvider.php`, package listener, observer, subscriber, model boot hook, scheduled command, or dispatched event must update the relevant section below and refresh "Last verified". The hook at `/home/jelte/projects/.claude/hooks/check-data-flows.sh` (when working from the workspace root) fires on edits to those files and reminds you.

Last verified against codebase: 2026-05-29 (refreshed for `SyncInventoryOnOrderStatusChange` in v0.23.0)

---

## Auto-scheduled commands

These commands register themselves into the consumer's Laravel scheduler via `app->booted()` in their ServiceProvider. They fire automatically whenever the consumer has a `schedule:run` cron wired on the host. Each is gated by a config key with a default of `true` — consumers can disable individual schedules without losing the command itself.

| Command | Cadence | Subsystem | Config gate (default) | Effect |
| --- | --- | --- | --- | --- |
| `inventory:release-expired` | every 5 min | Inventory | `inventory.schedule.enabled` (`true`) | finds `stock_reservations` with `status='pending'` and `expires_at < now()`, releases them (deletes the reservation row, creates a release `stock_movements` entry), dispatches `ReservationReleased` |
| `pricing:expire-compare-at` | hourly | Pricing | `pricing.schedule.enabled` (`true`) | finds `prices` where `compare_at_until < now()`, nulls `compare_at_amount`, dispatches `CompareAtPriceExpired` |
| `logging:prune-domain-logs` | daily | Logging | `domain-log.schedule.enabled` (`true`) | deletes `domain_logs` rows older than `config('domain-log.retention_days')` (default 90) |

## Console commands (registered, not auto-scheduled)

Registered via `$this->commands([...])` but not scheduled — consumers wire them into their own `routes/console.php` if they want them to run periodically.

| Command | Subsystem | Purpose |
| --- | --- | --- |
| `commerce:prune-carts` | Commerce | deletes guest carts with `expires_at <= now()` (no-op if consumer never stamps `expires_at` on carts) |
| `payment:prune-webhook-events` | Payment | deletes `webhook_events` older than `config('payment.webhook_retention_days')` (default 90); accepts `--days=N` |
| `taxonomy:recompute-category-counts` | Taxonomy | rebuilds `category_morph_counts` denormalization table from scratch; **recovery command — use only if counts drift** |
| `flowchain:publish` | FlowChain | publishes a chain definition from the package into the consumer app (dev/ops only) |
| `flowchain:list` | FlowChain | lists all registered chains (ops) |
| `flowchain:check-contracts` | FlowChain | validates step payload contracts against `initialPayloadShape()` (dev) |
| `flowchain:verify-tests` | FlowChain | verifies test coverage of chains (dev) |

## Event subscribers

Auto-subscribed by their domain's ServiceProvider via `Event::subscribe(...)`. Each routes its subsystem's events through `LogDispatcher` to the `domain_logs` table.

| Subscriber | Subsystem | Listens to | Writes |
| --- | --- | --- | --- |
| `AgentLogSubscriber` | Agent | `ToolInvoked`, `ToolInvocationFailed`, `DynamicClientRegistered` | `domain_logs` (agent channel) |
| `CommerceLogSubscriber` | Commerce | `CartUpdated`, `CartClaimed`, `CartCleared`, `OrderCreated`, `OrderStatusChanged` | `domain_logs` (commerce channel) |
| `FlowChainLogSubscriber` | FlowChain | `FlowChainStarted`, `FlowChainCompleted`, `FlowChainFailed`, `FlowChainStepFailed`, `FlowChainStepCompleted` | `domain_logs` (flowchain channel) |
| `InventoryLogSubscriber` | Inventory | `StockAdjusted`, `StockReleased`, `StockReservationFailed`, `ReservationCreated`, `ReservationConfirmed`, `ReservationReleased` | `domain_logs` (inventory channel) |
| `PaymentLogSubscriber` | Payment | `PaymentSucceeded`, `PaymentFailed`, `PaymentRefunded` | `domain_logs` (payment channel) |
| `PricingLogSubscriber` | Pricing | `PriceCreated`, `PriceUpdated`, `PriceDeleted`, `CompareAtPriceExpired`, `VoucherApplied` | `domain_logs` (pricing channel) |
| `ShipmentLogSubscriber` | Shipping | `ShipmentCreated`, `ShipmentReady`, `ShipmentDispatched`, `ShipmentDelivered`, `ShipmentReturnedToSender`, `ShipmentLost` | `domain_logs` (shipping channel) |
| `MaintainCategoryCounts` | Taxonomy | `CategoryAttached`, `CategoryDetached`, `CategoryMoved`, `CategoryDeleted` | `category_morph_counts` (incremental delta updates) |

## Event listeners

| Listener | Subsystem | Trigger | Effect | Gate |
| --- | --- | --- | --- | --- |
| `CreateShipmentForNewOrder` | Shipping (registered in Commerce SP) | `OrderCreated` | calls `CreateShipment` action — writes `shipments`, `shipment_items` (one Pending shipment per order, using the order's snapshotted shipping method) | `shipping.auto_create_shipment` (default `true`) |
| `SyncInventoryOnOrderStatusChange` | Commerce | `OrderStatusChanged` | on `Pending → Confirmed`: calls `ConfirmReservation` to flip all pending reservations to Confirmed. On `* → Cancelled`: releases all pending or confirmed reservations via `ReleaseReservation`, restoring stock. Status-guarded actions make it safe to run alongside a consumer payment-success/fail listener that also calls them. | none — always on |

## Model observers

| Observer | Model | Triggers | Effect |
| --- | --- | --- | --- |
| `CategoryObserver` | `Taxonomy\Category` | `updated` (parent_id change) | dispatches `CategoryMoved` |
| `CategoryObserver` | `Taxonomy\Category` | `deleting` | validates no children remain, dispatches `CategoryDeleted` |

## Model boot hooks (`static::saving` / `::deleting` / etc.)

These live inside model classes themselves, not in observers.

| Model | Hook | Effect |
| --- | --- | --- |
| `Media\Models\Media` | `deleting` | if `type=Upload`, deletes the physical file from disk via `Storage::disk($media->disk)->delete($media->path)` |
| `Pricing\Models\Price` | `saving` | validates `compare_at_amount > amount`; throws `InvalidCompareAtPriceException` if violated |

## Events dispatched (public surface — consumers may subscribe)

State changes the package emits. Past-tense, `final readonly class`, `Dispatchable` trait. Listed by domain.

**Agent.** `ToolInvoked`, `ToolInvocationFailed`, `DynamicClientRegistered`.

**Commerce.** `CartUpdated`, `CartClaimed`, `CartCleared`, `OrderCreated`, `OrderStatusChanged`.

**FlowChain.** `FlowChainStarted`, `FlowChainCompleted`, `FlowChainFailed`, `FlowChainStepCompleted`, `FlowChainStepFailed`.

**Inventory.** `StockAdjusted`, `StockReleased`, `StockReservationFailed`, `ReservationCreated`, `ReservationConfirmed`, `ReservationReleased`.

**Media.** `MediaStored`, `MediaDeleted`. *(No package subscriber yet — admin-activity logging deferred until multi-user.)*

**Payment.** `PaymentSucceeded`, `PaymentFailed`, `PaymentRefunded`.

**Pricing.** `PriceCreated`, `PriceUpdated`, `PriceDeleted`, `CompareAtPriceExpired`, `VoucherApplied`.

**Shipping.** `ShipmentCreated`, `ShipmentReady`, `ShipmentDispatched`, `ShipmentDelivered`, `ShipmentReturnedToSender`, `ShipmentLost`.

**Taxonomy.** `CategoryAttached`, `CategoryDetached`, `CategoryMoved`, `CategoryDeleted`, `TagAttached`, `TagDetached`. *(No package LogSubscriber — only `MaintainCategoryCounts` listens.)*

## Tables owned by subsystem

What each subsystem reads from and writes to. Consumers should not write to these tables directly except through the package's actions.

| Subsystem | Tables |
| --- | --- |
| Commerce | `customers`, `customer_groups`, `orders`, `order_lines`, `carts`, `cart_items` |
| Inventory | `stock_items`, `stock_movements`, `stock_reservations` |
| Logging | `domain_logs` |
| Location | `addresses` |
| Media | `media` |
| Payment | `payments`, `payment_profiles`, `webhook_events` |
| Pricing | `prices`, `price_lists`, `vouchers` |
| Shipping | `shipments`, `shipment_items` |
| Tax | `tax_rates` |
| Taxonomy | `categories`, `categorizables`, `tags`, `taggables`, `category_morph_counts` |
| Translation | `translations`, `locale_groups` |
| Currency / FlowChain / Storefront / Agent | no tables — enum, orchestration, read-aggregation, or integration layers |

## Subsystems with no periphery

For completeness — when adding to these, also reconsider whether you're adding periphery and update the relevant section above.

- **Currency** — foundational data only, no events
- **Location** — provides address data only
- **Storefront** — read-aggregation only, no writes
- **Tax** — configuration/calculation only
- **Translation** — multilingual data tables, no events
