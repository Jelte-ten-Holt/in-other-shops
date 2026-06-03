# Periphery — in-other-shops

This package contributes periphery to every consumer that installs it: auto-scheduled commands, event listeners, observers, subscribers, model boot hooks, the events it dispatches, and the public symbols consumers attach to and call. **This doc is the authoritative list of what this package adds to a consumer's runtime, plus the API surface consumers depend on.**

Consumers reference this file from their own `docs/periphery.md` rather than re-deriving it. The version that applies is whatever is pinned in their `composer.lock` — bump the package and the new version of this doc applies.

**Definition of done:** any change to a `*ServiceProvider.php`, package listener, observer, subscriber, model boot hook, scheduled command, dispatched event, **public `Has*` contract, `InteractsWith*` trait, public action signature, registry class method, Filament Schema static factory, or FlowChain step payload contract** must update the relevant section below and refresh "Last verified". The hook at `/home/jelte/projects/.claude/hooks/check-data-flows.sh` (when working from the workspace root) fires on edits to those files and reminds you.

Scope split: sections "Auto-scheduled commands" through "Tables owned by subsystem" cover the **runtime periphery** — what fires in a consumer's app when the package is installed. The "External surface" section at the bottom covers the **API contract** — what consumers depend on at the type level. Both are this package's contract with its consumers.

Last verified against codebase: 2026-05-29 (runtime sections refreshed for `SyncInventoryOnOrderStatusChange` in v0.23.0; External surface section added 2026-05-29 — initial population is opportunistic from CLAUDE.md, deepen on next audit; pricing:expire-compare-at row corrected via pathology-reviewer cross-check; 2026-05-29 pathology fixes applied — B-1 bulk-detach now dispatches `CategoryDetached`, B-2 attach/detach/move/delete now transactional, B-3 `morph_alias` widened to 255 + over-length guard, B-4 recompute advisory-locked + upsert, C-1 `UpdateOrderStatus` transactional, D-2 PricingLogSubscriber `commerce` channel confirmed intentional; 2026-05-29 second-pass — A-3/A-5 `PriceData` rejects orphan/past strikethrough, A-4 expiry also dispatches `PriceUpdated(fromExpiry)`, A-1 picker timezone pinned, B-6 `onMoved` re-derives from pivot, B-7 listener N+1 removed via `CategoryAncestry`, C-2 `UpdateOrderStatus` locks + idempotent same-status no-op; 2026-06-01 added `Commerce\Order\Actions\ResolvePreOrderAudience` + `PreOrderRecipient` DTO + `OrderLine::scopePreOrder()` to the External surface — pre-order engagement; 2026-06-01 `Taxonomy\Category` now implements `HasMedia` (cover image) — new Taxonomy→Media soft dependency, no migration (polymorphic `mediables` pivot, `category` morph alias already registered); `Taxonomy\Category` also now implements `HasTags` (intra-domain, no new cross-domain edge) for a `featured`-style category flag, no migration (`taggables` pivot, same `category` morph alias); 2026-06-02 **Variants domain Phase 1 landed** (unreleased) — new `Variants` subsystem (`options`, `option_values`, `variants`, `option_value_variant`, `optionables` tables; morph aliases `option`/`option_value`/`variant`); `HasVariants`/`InteractsWithVariants` contract+trait now ship; package `Variant` adopts `HasPrices`/`HasStock`/`HasMedia`, `Option`/`OptionValue` adopt `HasTranslations`; `VariantCreated`/`VariantDeleted` event classes exist but are not yet dispatched (Phase 2 actions); 2026-06-02 **Variants Phase 2 landed** (unreleased) — package `Variant` now implements `HasCart` (Variants→Commerce edge); **new `InteractsWithCart` `deleting` guard blocks deleting any cart-able referenced by a live cart** (config `commerce.cart.guard_cartable_deletion`, default true — affects Product/Bundle in both consumers, verify in-other-worlds before release); public actions `CreateVariant`/`GenerateVariants`/`CreateDefaultVariant`/`DeleteVariant` (the last three dispatch `VariantCreated`/`VariantDeleted`); `HasVariants` contract extended with `lowestVariantPrice`/`hasVariantInStock`/`variantStockTotal` (trait-defaulted, non-breaking); `CartReferencesCartableException` added. Storefront variant surfacing deferred — no consumer browses variants via the Storefront API; 2026-06-02 **Variants Phase 3 landed** (unreleased) — `OptionResource` (standalone admin for the global Option/OptionValue catalog, translated name + per-value labels; consumer-registered like other package resources) and `VariantsSchema` (`axesField`/`variantsRepeater`/`fillFormData`/`saveFormData`). Generate-combinations Filament action + owner-price cascade are consumer-side, deferred to Phase 5; 2026-06-02 **`Variants\OptionValue` adopts `HasMedia`** (unreleased, suggested v0.29.0) — an optional single **swatch** image per value (`swatch` collection) for the storefront variant picker, managed via a per-value `FileUpload` in `OptionResource`. No migration (polymorphic `mediables`, `option_value` alias already registered); no new dep edge (Variants→Media already exists))

---

## Auto-scheduled commands

These commands register themselves into the consumer's Laravel scheduler via `app->booted()` in their ServiceProvider. They fire automatically whenever the consumer has a `schedule:run` cron wired on the host. Each is gated by a config key with a default of `true` — consumers can disable individual schedules without losing the command itself.

| Command | Cadence | Subsystem | Config gate (default) | Effect |
| --- | --- | --- | --- | --- |
| `inventory:release-expired` | every 5 min | Inventory | `inventory.schedule.enabled` (`true`) | finds `stock_reservations` with `status='pending'` and `expires_at < now()`, releases them (deletes the reservation row, creates a release `stock_movements` entry), dispatches `ReservationReleased` |
| `pricing:expire-compare-at` | hourly | Pricing | `pricing.schedule.enabled` (`true`) | finds `prices` where `compare_at_until < now()` and `compare_at_amount` is not null, **promotes `compare_at_amount` into `amount`**, then nulls both `compare_at_amount` and `compare_at_until`. Dispatches `CompareAtPriceExpired` (carries the pre-promotion amount) **and `PriceUpdated` with `fromExpiry: true`** (generic amount-change signal for cache/index/denorm consumers; the log subscriber skips the generic line for this path). The amount rewrite means `prices.amount` is effectively owned by this scheduler on the hour — other writers must coordinate (see also: cron-owned-column concurrency in pathology-reviewer rulebook) |
| `logging:prune-domain-logs` | daily | Logging | `domain-log.schedule.enabled` (`true`) | deletes `domain_logs` rows older than `config('domain-log.retention_days')` (default 90) |

## Console commands (registered, not auto-scheduled)

Registered via `$this->commands([...])` but not scheduled — consumers wire them into their own `routes/console.php` if they want them to run periodically.

| Command | Subsystem | Purpose |
| --- | --- | --- |
| `commerce:prune-carts` | Commerce | deletes guest carts with `expires_at <= now()` (no-op if consumer never stamps `expires_at` on carts) |
| `payment:prune-webhook-events` | Payment | deletes `webhook_events` older than `config('payment.webhook_retention_days')` (default 90); accepts `--days=N` |
| `taxonomy:recompute-category-counts` | Taxonomy | rebuilds `category_morph_counts` denormalization table from scratch; **recovery command — use only if counts drift**. Takes an advisory lock (MySQL `GET_LOCK` / Postgres `pg_try_advisory_lock`; no-op on SQLite) so two runs can't collide, and writes the rebuild via upsert so a concurrent attach/detach during recovery can't crash it on a duplicate key |
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
| `PricingLogSubscriber` | Pricing | `PriceCreated`, `PriceUpdated`, `PriceDeleted`, `CompareAtPriceExpired`, `VoucherApplied` | `domain_logs` (**`commerce` channel — intentional**, per audit D-2: price changes are commercial audit events and stream alongside orders/payments; there is no separate `pricing` channel in the default Logging config. The one deliberate exception to the per-domain channel convention) |
| `ShipmentLogSubscriber` | Shipping | `ShipmentCreated`, `ShipmentReady`, `ShipmentDispatched`, `ShipmentDelivered`, `ShipmentReturnedToSender`, `ShipmentLost` | `domain_logs` (shipping channel) |
| `MaintainCategoryCounts` | Taxonomy | `CategoryAttached`, `CategoryDetached`, `CategoryMoved`, `CategoryDeleted` | `category_morph_counts` (incremental delta updates; rejects morph aliases over 255 chars with `MorphAliasTooLongException` — an unregistered FQCN morph map. The Attach/Detach actions and `Category::save()`/`delete()` wrap the pivot/lifecycle write and this walk in one transaction so a failed delta rolls the write back too) |

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
| **Any model using `Commerce\Cart\Concerns\InteractsWithCart`** (Variant + every consumer cart-able: Product, Bundle, …) | `deleting` | blocks deletion when a **live** cart (`expires_at` null or future) references it — throws `CartReferencesCartableException`. Gated by `commerce.cart.guard_cartable_deletion` (default true). Affects both consumers; expired guest carts don't block (they're pruned) |

## Events dispatched (public surface — consumers may subscribe)

State changes the package emits. Past-tense, `final readonly class`, `Dispatchable` trait. Listed by domain.

**Agent.** `ToolInvoked`, `ToolInvocationFailed`, `DynamicClientRegistered`.

**Commerce.** `CartUpdated`, `CartClaimed`, `CartCleared`, `OrderCreated`, `OrderStatusChanged`.

**FlowChain.** `FlowChainStarted`, `FlowChainCompleted`, `FlowChainFailed`, `FlowChainStepCompleted`, `FlowChainStepFailed`.

**Inventory.** `StockAdjusted`, `StockReleased`, `StockReservationFailed`, `ReservationCreated`, `ReservationConfirmed`, `ReservationReleased`.

**Media.** `MediaStored`, `MediaDeleted`. *(No package subscriber yet — admin-activity logging deferred until multi-user.)*

**Payment.** `PaymentSucceeded`, `PaymentFailed`, `PaymentRefunded`.

**Pricing.** `PriceCreated`, `PriceUpdated` (fires on every `prices.amount` change including the scheduled strikethrough promotion, where `fromExpiry: true`), `PriceDeleted`, `CompareAtPriceExpired`, `VoucherApplied`. A consumer wiring cache-bust / search-reindex / denormalized-price listeners should subscribe to `PriceUpdated` alone — it covers both direct edits and scheduled promotions. `CreatePrice`/`UpdatePrice` reject an orphan or past-dated strikethrough at the `PriceData` boundary (`InvalidCompareAtPriceException`); direct model writes (the expiry sweep, factories) bypass that guard deliberately.

**Shipping.** `ShipmentCreated`, `ShipmentReady`, `ShipmentDispatched`, `ShipmentDelivered`, `ShipmentReturnedToSender`, `ShipmentLost`.

**Taxonomy.** `CategoryAttached`, `CategoryDetached`, `CategoryMoved`, `CategoryDeleted`, `TagAttached`, `TagDetached`. *(No package LogSubscriber — only `MaintainCategoryCounts` listens.)*

**Variants.** `VariantCreated` (dispatched by `CreateVariant`, and thus by `GenerateVariants`/`CreateDefaultVariant`), `VariantDeleted` (dispatched by `DeleteVariant`). No LogSubscriber (catalog-structure edits are admin activity, deferred until multi-user — same posture as Media/Taxonomy).

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
| Variants | `options`, `option_values`, `variants`, `option_value_variant`, `optionables` |
| Currency / FlowChain / Storefront / Agent | no tables — enum, orchestration, read-aggregation, or integration layers |

## Subsystems with no periphery

For completeness — when adding to these, also reconsider whether you're adding periphery and update the relevant section above.

- **Currency** — foundational data only, no events
- **Location** — provides address data only
- **Storefront** — read-aggregation only, no writes
- **Tax** — configuration/calculation only
- **Translation** — multilingual data tables, no events

---

## External surface (consumer-facing API)

Symbols this package exposes that consumers attach to or call. **Changing any of these is a breaking change for at least one consumer.** Closes the gap the planned `consumer-surface-map.md` was meant to fill — that doc was never written; this section replaces it.

Two consumers today: **in-other-worlds** (`/home/jelte/projects/in-other-worlds`) and **bianka-shop-one** (`/home/jelte/projects/bianka-shop-one`, pre-Phase-1). Each consumer's `docs/periphery.md` mirrors this section from the consumer side ("symbols I depend on"). When a change here lands, audit both consumer periphery docs for impact before releasing.

**Initial population is opportunistic** — derived from `CLAUDE.md`, the bianka BRIEF, and grep-spotting on a few files. The first thorough audit happens on the next periphery-touching brief; refresh "Last verified" at the top of this doc when that happens.

### Capability contracts (`Has*`)

The trait companions (`InteractsWith*`) are documented alongside their contracts in CLAUDE.md "Key Patterns". Both ship together.

| Domain | Contract | Trait | Used by |
| --- | --- | --- | --- |
| Commerce | `HasCart` | `InteractsWithCart` | **package model: `Variants\Variant`** (cart-able unit). in-other-worlds: Product, Bundle. bianka: Product, Bundle, Variant. *Trait now also carries the cart-deletion `deleting` guard (see Model boot hooks).* |
| Inventory | `HasStock` | `InteractsWithStock` | **package model: `Variants\Variant`** (per-variant stock). in-other-worlds: Product, Bundle. bianka: Product, Bundle, Variant |
| Media | `HasMedia` | `InteractsWithMedia` | **package models: `Taxonomy\Category`** (cover image), **`Variants\Variant`**, **`Variants\OptionValue`** (`swatch` collection — single value-level swatch image). in-other-worlds: Content, Product, Bundle. bianka: Product, Bundle |
| Pricing | `HasPrices` | `InteractsWithPrices` | **package model: `Variants\Variant`** (per-variant price). in-other-worlds: Product, Bundle. bianka: Product, Bundle, Variant |
| Storefront | `HasStorefrontPresence` | `InteractsWithStorefrontPresence` | in-other-worlds: Product, Bundle. bianka: Product, Bundle |
| Taxonomy | `HasCategories`, `HasTags` | `InteractsWithCategories`, `InteractsWithTags` | **package model: `Taxonomy\Category` adopts `HasTags`** (intra-domain; enables a `featured`-style category flag, semantics consumer-side). in-other-worlds: Content, Product, Bundle. bianka: Product, Bundle |
| Translation | `HasTranslations` | `InteractsWithTranslations` | **package models: `Variants\Option`** (name), **`Variants\OptionValue`** (label) — column-translation. bianka: Product, Bundle, Category, Tag |
| Translation | `HasLocaleGroup` | `InteractsWithLocaleGroup` | in-other-worlds: Product, Bundle (deliberately not adopted in bianka — see bianka BRIEF §4.9) |
| Variants | `HasVariants` | `InteractsWithVariants` | **Contract + trait ship (unreleased).** Methods: `variants`, `options` (declared axes), `hasVariants`, `lowestVariantPrice`, `hasVariantInStock`, `variantStockTotal` (all trait-defaulted). No public `HasOptions` contract — owner→option link is internal. bianka: Product (planned). in-other-worlds: not adopted — flat SKUs only |

**Adding/removing/renaming a contract or trait** breaks every consumer that opts in. Adding a new method to an existing contract is breaking unless the trait provides a default. Removing or retyping a method is always breaking.

### Public action signatures called by consumers

Actions consumers invoke directly. Constructor + `__invoke` signature is the contract.

| Action | Domain | Called from |
| --- | --- | --- |
| `Inventory\Actions\ReserveStock` | Inventory | in-other-worlds: `App\Actions\Checkout\Steps\ReserveItems`. bianka: planned for checkout FlowChain `ReserveItems` step |
| `Inventory\Actions\ConfirmReservation` | Inventory | in-other-worlds: indirectly via `OrderStatusChanged` listener `SyncInventoryOnOrderStatusChange` (now package-side) |
| `Inventory\Actions\ReleaseReservation` | Inventory | in-other-worlds: `App\Listeners\HandlePaymentFailed` |
| `Inventory\Actions\AdjustStock` | Inventory | in-other-worlds: admin only (`StockMovementResource`) |
| `Commerce\Cart\Http\Support\ResolveCurrentCart` | Commerce | in-other-worlds: `App\Http\Middleware\HandleInertiaRequests::cartSummary()`. bianka: planned for the same. |
| `Commerce\Order\Actions\ResolvePreOrderAudience` | Commerce | `__invoke(Model $purchasable): Collection<PreOrderRecipient>` — distinct pre-order recipients (guests + customers) for a purchasable, deduped by normalized email, shipment-agnostic. in-other-worlds: planned for the `PreOrderReleased` notification + Filament pre-order broadcast (pre-order engagement brief). |

Returns the `Commerce\Order\DTOs\PreOrderRecipient` DTO (`email`, `name`, `locale`, `customerId`) — also part of the surface. Pairs with the `OrderLine::scopePreOrder()` query scope (public).

Variants actions (consumer Filament / admin flows call these; Phase 3 wraps them in `VariantsSchema`):

| Action | Domain | Signature |
| --- | --- | --- |
| `Variants\Actions\CreateVariant` | Variants | `__invoke(Model&HasVariants $owner, array $optionValueIds = [], ?string $sku = null, ?int $position = null): Variant` — validates one-value-per-option + options-declared-on-owner, copies the owner's price template, dispatches `VariantCreated` |
| `Variants\Actions\GenerateVariants` | Variants | `__invoke(Model&HasVariants $owner, array $valueIdsByOption): Collection<Variant>` — declares axes, generates the cartesian product, skips existing combinations |
| `Variants\Actions\CreateDefaultVariant` | Variants | `__invoke(Model&HasVariants $owner): ?Variant` — flat-owner migration; carries price + stock to one default variant; null if already has variants |
| `Variants\Actions\DeleteVariant` | Variants | `__invoke(Variant $variant): void` — deletes variant + owned price/stock/media rows; cart-deletion guard fires first; dispatches `VariantDeleted` |

### Public events dispatched (consumer-subscribable)

Listed in full under "Events dispatched (public surface — consumers may subscribe)" above. Each is a `final readonly class` — changing constructor params is a breaking change for any consumer subscribing to that event.

Consumer subscriptions today:

| Event | Subscribed by |
| --- | --- |
| `Payment\Events\PaymentSucceeded` | in-other-worlds `App\Listeners\HandlePaymentSucceeded` |
| `Payment\Events\PaymentFailed` | in-other-worlds `App\Listeners\HandlePaymentFailed` |

Other events have no consumer-side subscribers today — they're routed through package log subscribers only. Adding a consumer-side subscriber to a new event makes that event part of this list.

### Filament Schemas (consumer-embedded form fragments)

Static-factory schemas consumers call from their own Filament Resources. Adding a method is non-breaking; renaming or changing a return type is breaking.

| Schema | Methods consumers call | Used by |
| --- | --- | --- |
| `Media\Filament\MediaSchema` | `fillFormData`, `saveFormData`, repeater factories | in-other-worlds, bianka |
| `Pricing\Filament\PricingSchema` | static factories | in-other-worlds, bianka |
| `Taxonomy\Filament\TaxonomySchema` | static factories | in-other-worlds, bianka |
| `Inventory\Filament\InventorySchema` | `stockFields`, static factories | in-other-worlds, bianka |
| `Translation\Filament\TranslationSchema` | `fillFormData`, `saveFormData` | bianka (in-other-worlds uses HasLocaleGroup pattern instead) |
| `Variants\Filament\VariantsSchema` | `axesField`, `variantsRepeater`, `fillFormData`, `saveFormData` | bianka (planned). Declares axes + edits existing variants' SKU/price/stock. **Generating new combinations + the owner-price cascade are consumer-side** — call the `GenerateVariants` action from a Filament Action against the real product form (deferred to Phase 5). |

### FlowChain published surface

Published chains and their step payload contracts are public API — consumers `flowchain:publish` a chain, override `steps()`, and depend on every existing step's `@reads` / `@writes` shape.

| Published chain | Step payload contract | Used by |
| --- | --- | --- |
| `Commerce\Cart\FlowChains\AddToCartChain` | see chain definition | in-other-worlds publishes a copy at `app/Project/FlowChains/Cart/AddToCart.php`, inserts `RecordCartItemAttribution` step |

Adding a step at the end of a chain is non-breaking. Inserting in the middle, renaming, removing, or changing a step's payload `@writes` field is breaking. See [`src/FlowChain/README.md`](../src/FlowChain/README.md) §Publishing.

### Consumer-side shape-parity files (Part C)

Files that exist in every consumer in roughly the same shape, wrapping package APIs. When the underlying package API changes, all of these need parallel updates — a checklist for cross-consumer audits.

| Consumer file shape | Wraps | in-other-worlds path | bianka path (planned) |
| --- | --- | --- | --- |
| `ClaimGuestCart` action | guest-cart claim/merge on login | `app/Actions/Cart/ClaimGuestCart.php` | `app/Actions/Cart/ClaimGuestCart.php` |
| `HandlePaymentSucceeded` listener | confirms reservation, advances order, sends mail | `app/Listeners/HandlePaymentSucceeded.php` | planned in Phase 3 |
| `HandlePaymentFailed` listener | releases reservations, advances order to Cancelled | `app/Listeners/HandlePaymentFailed.php` | planned in Phase 3 |
| `ReserveItems` checkout step | calls `ReserveStock` on each cart line | `app/Actions/Checkout/Steps/ReserveItems.php` | planned in Phase 3 |
| `HandleInertiaRequests` cartSummary | calls `ResolveCurrentCart` | `app/Http/Middleware/HandleInertiaRequests.php` | planned in Phase 1 |
| `OrderConfirmation` mail | rendered from Order + lines | `app/Mail/OrderConfirmation.php` | planned in Phase 3 |

When any of these is edited in one consumer, check whether the other needs the same change. When the underlying package API shifts, audit all rows.

### What is NOT part of the external surface

- Internal model classes intended for swap via registry (`Currency.php`, `Commerce.php`, `Taxonomy.php`) — consumers configure via `config/{domain}.php` model keys; the registry method names are public, the resolved class names are not.
- Migrations — applied by Laravel, not called.
- Internal `Domain/Exceptions/` subclasses — only the parent `{Domain}Exception` and the specific exception types in the "Exception strategy" CLAUDE.md section are public. Other exception classes can be added/renamed freely.
- Test factories — package-internal even though they ship with the package.
- `protected` / `private` methods on any class, even in a publicly-extended trait.
