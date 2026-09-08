# Brief — complexity audit, cheap-and-effective quadrant

Status: **draft 2 + Release 1 reconciled** (2026-09-07). Release 1 PRs open: [#24 conventions](https://github.com/Jelte-ten-Holt/in-other-shops/pull/24), [#25 deletions](https://github.com/Jelte-ten-Holt/in-other-shops/pull/25), [#26 query shape](https://github.com/Jelte-ten-Holt/in-other-shops/pull/26) (#26 is stacked on #25 — merge #25, retarget #26, then #24). Not tagged. Source: `docs/audits/2026-09-07/complexity-audit.md` §10, top-left quadrant only. Draft 1 was attacked by a design reviewer (missed references, the seven leans, sequencing, convention wording) and a pathology reviewer (concurrency, transactions, migrations, deploy windows); every finding is folded in below. Changes from draft 1 are marked **Δ**.

## 1. Goal

Land the cheap-and-effective items in three package releases, each bumped into both consumers the same day, without changing anything a shopper or admin can observe except: price edits become audited (X1), shipments are created when an order is **confirmed** rather than when it is created (X2), parcels need one click instead of two (C23), and admin pages that were slow stop being slow.

## 2. Ground rules

- **Push-and-bump.** Each release is tagged once (never moved), then both consumers are bumped to the same version in the same sitting, `docs/periphery.md` status lines corrected in the same commit (bianka's currently says v0.68.0 against `^0.70.0`).
- **Δ Every DDL migration in releases 2 and 3 is run against MySQL 8 before tagging**, with the specific row shapes named in the PR (not only SQLite; FK names are schema-global, DDL is non-transactional, `up()` must be recovery-aware). Package tests still run on SQLite, so migrations branch on driver where SQLite cannot express the step (precedent: `2026_07_02_000002_restrict_shipment_items_order_line_fk.php`).
- **Δ Seeder tests are a release gate.** Bianka's entrypoint runs `db:seed --force` under `set -e` at every boot, so a seeder that references a deleted symbol is a failed deploy, not a red test. `DemoCatalogSeederTest` (bianka) and IOW's seeder test must be green before releases 2 and 3 are tagged.
- **Periphery doc in the same PR** — in all three repos when the external surface changes. `docs/adding-a-new-domain.md` gains the `enabled` key in PR 7.
- **Disposition manifest (§7).** Each item ends `done`, `blocked (reason)`, `deferred (reason)` or `dropped (reason)`. A consumer reference the greps missed blocks that item, not the PR.
- **Tests.** Deleting a feature deletes its tests; a changed write path adds a test that proves the new path fires the audit row; a changed trigger adds the negative cases. Package suite and both consumer suites green before each tag. **Δ A deleted test file that carries an assertion still guarding live code moves that assertion, it doesn't drop it** (`AutoCreateShipmentTest:49-53` is the only proof that `CreateShipment::attachItems` attaches every line; IOW `ShopControllerTest` "resolves the default price list once" becomes a query count on `priceFor()`).

## 3. Periphery map — what fires differently after this brief

| Actor | Before | After |
|---|---|---|
| `Commerce\Order\Listeners\CreateShipmentForNewOrder` on `OrderCreated` | Pending shipment before payment | **removed** |
| **Δ** `Commerce\Order\Listeners\CreateShipmentForConfirmedOrder` on `OrderStatusChanged` (`to === Confirmed`) | — | **new**; runs inside `UpdateOrderStatus`'s locked transaction (precedent `SyncInventoryOnOrderStatusChange`); creates one shipment when the order has a shipping method and none exists; honours `shipping.auto_create_shipment`; unknown method → `Log::error` + return (a throw would roll back the confirmation and Stripe would retry into the same wall). Covers webhook, admin `updateStatus`, and any future manual-payment confirm |
| `Pricing\Events\Price{Created,Updated,Deleted}` | dispatched by the three actions only | dispatched from `Price` model `created`/`updated`/`deleted` hooks — every write path incl. the Filament repeater. Also fires from factories/seeders (see PR 5) |
| `PricingLogSubscriber` | never fires from either admin | fires for every price write; on bianka inside Filament's save transaction (rolls back with it — correct); `LogDispatcher` already isolates handler failures so a log error cannot abort a price save |
| `inventory:reconcile` | registered, scheduled by nobody | **Δ** scheduled via the existing `inventory.schedule.enabled` switch with the cron under `inventory.schedule.reconcile` (same switch as `release-expired`, not a parallel one); read-only; **Δ** dispatches `InventoryDriftDetected` on a dirty report so a consumer can alert (bianka: same mechanic as `OrderConfirmationBlocked → AlertOperatorToBlockedOrder`) |
| `CommerceLogSubscriber` cart handlers | audit row + `COUNT` per cart mutation | **removed** (events stay) |
| `FlowChainLogSubscriber` `Started`/`Completed` | Info row per run | **removed**; `Failed`/`StepFailed` stay |
| `Agent\Support\ToolRegistry` | instantiates every tool per request | `classes()` static; nothing instantiated until invoked. **Δ** `AgentToolContract` keeps its static `identifier()` — opgginc's `ToolInterface::name()` needs it and all 24 IOW tools declare it |
| **Δ** `DomainServiceProvider` | `boot()` always runs everything, children override `boot()` | base `boot()` runs config merge + morph aliases always, then `bootDomain()` only when `{key}.enabled !== false`; migrations, translations, views, subscriber, commands, schedule and **child extras** move into `bootDomain()`. Applies to `DomainServiceProvider` domains only (Currency's existing `currency.enabled` means enabled *currencies* and is untouched) |
| **Δ** `Tracking\FlowChains\Steps\{RecordCartItemAttribution,SnapshotCartItemAttributions}` | always write | early-return when `tracking.enabled === false` (a disabled domain whose step is still listed in a published chain must not write, and must not throw on a DB without the tables) |
| Migrations | — | forward drop migrations: release 2 `option_value_variant`, `optionables`, then `variants` (pivots first — MySQL refuses to drop a referenced parent), + `ready → pending` UPDATE + `shipments.problem_reason`; release 3 `prices.price_list_id` (dedupe → FK → new unique → old unique → column), `price_lists`, `customers.customer_group_id`, `customer_groups`, `payment_profiles` |
| `ShipmentStatus::Ready` | reachable | **Δ two-step**: release 2 removes it from `allowedTransitions`, deletes the only writer, runs the UPDATE; release 3 deletes the enum case. Closes the rolling-deploy window where an old container writes `ready` after the UPDATE and the new code fatals on hydration |
| Consumer periphery | bianka `HandlePaymentSucceeded::createShipmentIfMissing`, `config/shipping.php:55` | both removed (**only line 55** — that file also carries zones/methods/carriers); bianka `config/purchasing.php` + `config/tracking.php` with `'enabled' => false` |

**Removed or re-signatured public surface** (references enumerated per PR in §4; this is the list to sweep three `periphery.md` files for): `Pricing\Actions\{CreatePrice,UpdatePrice,DeletePrice}`, `Pricing\DTOs\PriceData`, `Pricing\Models\PriceList`, `Pricing::defaultPriceList()`, `Pricing::priceList()`, `HasPrices::priceFor(Currency, ?PriceList, int)` → `priceFor(Currency, int $quantity = 1)`, `CalculateTotal` and `ResolvePrice` lose their `priceList` parameter, `Commerce\Customer\Models\CustomerGroup`, `Commerce::customerGroup()`, `Commerce\Customer\Actions\{CreateCustomer,UpdateCustomer}`, `Payment\Actions\InitiatePayment` (**Δ `InitiatePaymentResult` stays** — `OpenPaymentSession` returns it to both consumers' pay controllers; rename later), `Payment\Models\PaymentProfile`, `Payment::paymentProfile()`, `HasPaymentProfiles`, `ManagesCustomers`, `PaymentGateway::customerDashboardUrl()`, `Tax\Actions\ResolveTaxRate(Address, …)` → `(string $countryCode, …)`, `HasLocaleGroup::{inLocale,locale}` and `HasStock::stockMovements()` (**Δ contract members — periphery external-surface entries in all three repos**), the `Variant` half of Variants, 6 relation managers, `ListCategoryBrowsables`, `ResolveShippingZoneForAddress`, `MarkShipmentReady`, `ShipmentReady`, `ShipmentStatus::Ready`.

## 4. Sequencing

### Release 1 — v0.71.0 — code only, no migrations; consumer work is bump + periphery only

**PR 1 · CLAUDE.md convention rewrites** (first, so later PRs follow them) — **Δ wording fixed for the two self-contradictions and three ambiguities the reviewer found**
- *Config-driven models:* "Do not add `models.*` keys or registry accessors for new models. An existing registry is stripped when its domain is opened for a registry-adjacent change (a model added, removed or re-homed) — not on every touch. Consumers drop the restated keys in the same bump."
- *Events:* "Dispatch a domain event when (a) a listener other than the domain's log subscriber exists, (b) a brief names one, or (c) the event is listed in `periphery.md` as consumer-subscribable surface. Otherwise the action or model hook calls the domain logger directly. Audit logging stays in the `{Domain}LogSubscriber` when an event exists; it does not justify minting one." (Pricing's three events are (c) — `PriceUpdated` documents itself as the canonical consumer signal — so X1 keeps dispatching.)
- *Contracts:* "A `Has*` contract that nothing in the package **or a consumer** type-hints is cargo-culting; one capability = one contract, wherever it lives."
- *Symmetry:* "Structure follows consumers. A domain whose public surface is a contract + trait is allowed to be just that." **Δ** Also delete CLAUDE.md's reference to "the architecture test" — no such test exists under `tests/`.
- *Tripwires:* "A reconcile command exists for a denormalisation the package relies on under a lock (the stock ledger vs `stock_items`) — those the package schedules itself. A reconcile command for a derived counter the package could compute at read time is a smell — remove the counter, not the tripwire." (Keeps S16 and C1 from contradicting.)
- *Domain gates:* "Every `DomainServiceProvider` domain honours `{key}.enabled` (default `true`) in `bootDomain()`; child providers put their extras in `bootDomain()`, not `boot()`."
- Record the audit §8 guardrails (price-resolution seam, `Customer ≠ User`, one tax-mode decision point, `QuoteCheckout` as the only cart→charge path). The "consumers call `priceFor()`, never query `prices`" guard holds today (both `app/` grep clean) but has no test — recorded as `deferred` in §7.

**PR 2 · zero-blast deletions** — C7, C8, C9, C10, C11, C14, D13, D16, D19, C22, S6, **Δ D14, D18 as free riders**
- Delete the 6 relation managers (`Pricing/PricesRelationManager`, `Taxonomy/{Categories,Tags}RelationManager`, `Commerce/{OrderLines,OrderAddresses}RelationManager`, `Media/MediaRelationManager`) + tests + lang keys + the two `AdminNavigationLabelsTest` assertions.
- Delete `Media/Actions/{StoreMedia,DeleteMedia}`, `Media/Events/{MediaStored,MediaDeleted}`, `Mediable::isImage()/url()`.
- **Δ** Delete `Commerce/Customer/Actions/{CreateCustomer,UpdateCustomer}` + `Events/{CustomerCreated,CustomerUpdated}` **and the package's own `CustomerResource/Pages/{CreateCustomer,EditCustomer}` `handleRecord*` overrides** that call them (Filament's default direct write is what both consumers' checkout already does). Leave `customer_group_id` untouched until PR 10.
- Delete `Storefront/Actions/ListCategoryBrowsables`, `Shipping/Actions/ResolveShippingZoneForAddress` (+ Location import, README dependency line).
- C14 dead API, each after a fresh grep of `src/ tests/` + both consumers: `PaymentStatus::Expired` (**Δ count `payments.status = 'expired'` on both production DBs first; record in manifest**), `HasStock::stockMovements()` + trait method, `isExpired/isResolved/isLowStock`, `HasLocaleGroup::{inLocale,locale}` + trait `scopeForLocale/scopeMonolingual` (**Δ** IOW's factories have unrelated `inLocale()` states — not ours), `setTranslations()`, `Refund::actor()`, `OrderFactory::status()`, `CustomerFactory::forGroup()`, `Currency::symbol()`, `Address::oneLine()`. READMEs: Translation (5 lines), Inventory (2), Location (1).
- **Δ C13 dropped.** `FakePaymentGateway` is booted by IOW's `tests/TestCase.php:36` and three bianka test files; it stays in `src/` like `Illuminate\Testing` does. Audit premise was wrong.
- `Storefront/Concerns/ResolvesEagerLoading`: drop the `HasMedia` branch; **Δ** while open, delete `HasAvailability` and key the resource off `HasStock` (D14).
- Media commands: drop `RunsAsSystemActor` (verified: every write is `saveQuietly`, prune is storage-only).
- Move `Relation::requireMorphMap()` to `SupportServiceProvider`.
- `ToolRegistry`: `classes()` static; delete the constructor loop and `all()/find()` (0 consumer callers; IOW mentions are comments); **Δ keep `AgentToolContract` static**. Fix `ToolRegistryTest`.
- **Δ** `commerce.cart.api.default_currency` → `currency.default` (D18): the only cross-domain reader was `VariantsSchema` (deleted in PR 4); `Cart.php:63-68` comment goes with it. Consumers don't set it.

**PR 3 · query shape + hygiene** — S1, S2, S3, S7, S8, S16, D2, D4, **Δ X4, X6**
- **Δ S1 and S2 collapsed.** `protected $with = ['translations']` on `Category`, `Tag`, `Option`, `OptionValue` loads all locales, which fixes the tree N+1 *and* the fallback-locale hole in one move; `ListCategoryTree` gets **no** locale-constrained `with()` (it would narrow the default and reintroduce X4). Query-count test asserts one `translations` query per tree and that a category translated only in the fallback locale resolves its name.
- **Δ X4:** `ResolvesEagerLoading` constrains to `whereIn('locale', [$current, config('translation.fallback')])` instead of `=`; same for `categories.translations`, `tags.translations`.
- S3: `OrderResource` `modifyQueryUsing(withSum('refunds','amount'))`; **Δ** column reads `(int) ($record->refunds_sum_amount ?? $record->refundedTotal())` so a consumer that overrides `modifyQueryUsing` degrades to the per-row query, never to "unrefunded".
- S7/S8: delete the three cart handlers + `cartContext()`; delete FlowChain `Started`/`Completed` handlers; update `AuditPipelineRowTest`.
- **Δ S16:** `InventoryServiceProvider::bootDomain()` schedules `inventory:reconcile` under the existing `inventory.schedule.enabled` switch, cron at `inventory.schedule.reconcile` (default daily). `ReconcileStock` dispatches `InventoryDriftDetected(report)` when dirty — the event is the thing that makes scheduling cut detection latency; a `Log::warning` nobody reads doesn't. Periphery: auto-scheduled + dispatched event. IOW `routes/console.php:32` comment updated (it cites inventory as the "consumer opts in" precedent, now inverted).
- D2: `function_exists('imagewebp')` guard with a clear exception; `suggest` `ext-exif`. D4: `stripe/stripe-php` `^20.0` in `require-dev` + `suggest`.
- **Δ X6:** fix the inverted `abandon_after_minutes` comment in `config/commerce.php:52-56` (reported upstream 2026-07-17).

Consumer work, release 1: bump both; periphery status lines; periphery external-surface entries for the two contract members and `InventoryDriftDetected`; IOW may wire an alert listener for drift (optional, bianka's `AlertOperator*` pattern).

### Release 2 — v0.72.0 — behaviour changes, first drop migrations; **Δ substantial consumer edits (listed)**

**PR 4 · C5 Variants `Variant` half** (before PR 5 — `CreateVariant` is the last `src/` caller of `CreatePrice`)
- Delete `Models/Variant`, the 4 actions, `HasVariants`/`InteractsWithVariants`, `VariantsSchema`, `Events/Variant*`, exceptions (each checked), `VariantFactory`, `lang/*/variant.php`, `variants.models.variant`, `Variants::variant()`, the `variant` morph alias, 26 test files. Residue: `docs/variants-design.md` (rewrite the header to "Options only; Variant retired 2026-09"), `Media/README.md:162`, `PruneMediaCommand.php:24` docblock, `Cart.php:63-68`.
- Delete the three create migrations; add **one** forward migration: **Δ** (1) count rows typed `variant` in `prices`, `stock_items`, `stock_movements`, `stock_reservations`, `mediables`, `translations`, `cart_items`, `order_lines`; delete those owned by the dead model, **abort with a named exception if `cart_items`/`order_lines` hold any** (the alias is gone after this release and `requireMorphMap()` makes hydration fatal); (2) `dropIfExists` `option_value_variant`, `optionables`, then `variants`. Every step guarded. **Novel convention — §5.1.**
- `Option`/`OptionValue`/`OptionResource` stay (bianka's Family axis).

**PR 5 · X1 pricing write path** — **Δ guards reshaped**
- `Price::booted()` `saving`: keep the not-above-price guard; **Δ normalise** `compare_at_until = null` when `compare_at_amount === null` (Filament drops hidden fields, so clearing a strikethrough leaves the old date behind — today a harmless orphan, must not become a 500); **Δ** the past-date guard fires only when `isDirty('compare_at_until')` and the new value is non-null (an untouched elapsed row must save; `ExpireCompareAtPricesTest` fixtures create elapsed rows by design — switch them to `createQuietly()`).
- **Δ** Ship both checks as repeater-item validation rules in `PricingSchema` (like `compareAtAmountRule`) so admins get a field error, not a Livewire 500; the model guard is the last line.
- `created/updated/deleted` hooks dispatch the three events. `ExpireCompareAtPrices`: `saveQuietly()` + its manual `PriceUpdated(fromExpiry: true)` — unchanged in effect.
- Delete `CreatePrice`, `UpdatePrice`, `DeletePrice`, `PriceData` (the DTO also carried `priceListId`); rewrite `CreateUpdatePriceTest` as model tests.
- **Δ** `LogDispatcher.php:41-43` docblock ("subscribers dispatch after the business transaction has committed") is already false for bianka's transactional Filament panel and for `PaymentSucceeded`; rewrite it.
- **Δ** Factories/seeders now emit `PriceCreated` → `domain_logs` rows with actor `unknown` (the CLAUDE.md tripwire) on every bianka boot seed. Seeders wrap price writes in `beginSystemAuditActor()`; package audit tests assert on message, not `latest('id')`.
- Tests: `AuditPipelineRowTest` cases — repeater save → `pricing` row; repeater row removal → "Price deleted." row; unchanged repeater save → no new row; clear-strikethrough-with-elapsed-date → no throw, `compare_at_until` nulled.
- **Δ Consumer work (release 2, not 3):** IOW tests using `CreatePrice`/`PriceData`: `ShopControllerTest`, `Checkout/CheckoutQuoteParityTest`, `Http/Resources/PurchasableDetailResourceTest`, `Models/BundleTest`, `Listing/ListingSortTest` → factory writes.

**PR 6 · X2 shipment timing** — **Δ trigger flipped**
- New `Commerce\Order\Listeners\CreateShipmentForConfirmedOrder` on `OrderStatusChanged`, `to === Confirmed`, registered beside `SyncInventoryOnOrderStatusChange` in `CommerceServiceProvider`. Why not `PaymentSucceeded`: a package listener fires before the consumer's `ConfirmOrder`, so it would mint a shipment for an order the expiry sweep already cancelled or that `ConfirmOrder` rejects for stock; two `Payment` rows on one order would both pass `exists()`; admin/manual confirmation never sees a payment event. Inside the order lock all three go away.
- Conditions: `shipping.auto_create_shipment` (kept as opt-out), `shipping_method_identifier` set, no existing shipment, method resolvable (else `Log::error` + return).
- Delete `CreateShipmentForNewOrder` + registration. **Δ** There is no `CreateShipmentForNewOrderTest`; `AutoCreateShipmentTest` exists and its attach-all-lines assertion (`:49-53`) moves into the new listener test.
- **Δ Tests (six):** confirmed → one shipment; created-but-unpaid → none; `AlreadyConfirmed` redelivery → still one; `StockUnavailable` → none; admin `updateStatus` to Confirmed → one; `auto_create_shipment=false` → none.
- **Δ One-off cleanup** in the same migration file as PR 9's data step: delete `Pending` shipments whose order is not `Confirmed` (the orphans X2 has been creating on IOW since launch). Count logged.
- README + periphery (Commerce README also gets the X7 drift fixes while open: 3 statuses not 7, cart API default, no `OrderFailed`).
- **Δ Consumer work:** bianka deletes `createShipmentIfMissing()` and **line 55** of `config/shipping.php`, keeps `PaymentWebhookTest` (it now guards the lifted behaviour), deletes only the config-pin test; IOW rewrites `CheckoutControllerTest:284-289` ("one Pending shipment after checkout") as "no shipment before payment" + "shipment after succeeded webhook", and `:382`'s `assertCount(0)` gets a positive counterpart so it stops passing vacuously.

**PR 7 · D7/D8 domain enable gate** — **Δ mechanism corrected**
- `DomainServiceProvider::boot()` = config-independent steps (morph aliases) + `if (config($this->configKey().'.enabled', true) !== false) $this->bootDomain();`. `bootDomain()` = migrations, translations, views, subscriber, commands, schedule. **Every child provider moves its `boot()` extras into `bootDomain()`** (Inventory, Pricing, Logging, Taxonomy, Commerce listeners + routes, …) — an early return in the base gates nothing otherwise. `scheduleWhenEnabled` also checks the gate.
- `'enabled' => true` in every `DomainServiceProvider` config; `docs/adding-a-new-domain.md` updated. **Δ** `tracking.php`'s "ships no settings" header rewritten.
- **Δ** Tracking steps early-return when disabled.
- Tests: disabled domain loads no migrations, subscribes nothing, schedules nothing, registers no child extras.
- Consumer work: bianka `config/purchasing.php` + `config/tracking.php` with `'enabled' => false` (tables stay; bianka never runs `migrate:fresh`); Tracking README bianka sentence fixed.

**PR 8 · D10/D12 domain edges**
- `ResolveTaxRate::__invoke(string $countryCode, …)`; **Δ consumer work:** both `ResolveTaxRateForOrder` steps + their tests (`ResolveTaxRateForOrderTest` in both).
- `PurchaseOrderLine`: drop the `tax_category` cast + import (`CreatePurchaseOrder` accepts the field from input but no caller passes it; column stays).

**PR 9 · C23 shipment `Ready`, step one** — **Δ scope corrected**
- Remove `Ready` from `allowedTransitions` (`Pending → [InTransit, Lost]`); delete `MarkShipmentReady`, `ShipmentReady`, subscriber handler, relation-manager action, lang keys. **The enum case stays until release 3** (deploy-window safety).
- **Δ Keep `MarkShipmentLost` and `MarkShipmentReturnedToSender` as typed actions** (the fold was cheap-ineffective and bianka's tests call them); add nullable `shipments.problem_reason`, written by both, shown in the relation manager, **cleared by the `ReturnedToSender → Pending` reship transition**.
- Migration: additive column + `UPDATE shipments SET status='pending' WHERE status='ready'` + PR 6's orphan cleanup. IOW staging **has** `ready` rows (its seeder mints them at `DatabaseSeeder.php:886`).
- **Δ Consumer work:** IOW `ListOrdersController.php:88` (`ShipmentStatus::Ready->value` in a priority map — **fatal on every orders page** after the bump if missed), `Account/ListPreOrdersController.php:126`, `DatabaseSeeder.php:886`, `ListPreOrdersControllerTest:109-114`; bianka `ShipmentNotificationTest` (its `readyShipment()` fixture, 18 uses, becomes a `pendingShipment()` and the `MarkShipmentReady` import goes).
- `docs/shipment-lifecycle-design.md` amended.

Consumer work, release 2 (total): IOW — 5 pricing test files, `CheckoutControllerTest` rewrite, `ListOrdersController`, `ListPreOrdersController` + test, seeder (Ready), `ResolveTaxRateForOrder` + test, periphery. Bianka — listener + config line, config-pin test, `ShipmentNotificationTest`, `config/purchasing.php`, `config/tracking.php`, `ResolveTaxRateForOrder` + test, periphery. Seeder tests green on both.

### Release 3 — v0.73.0 — MySQL DDL on shipped tables; **Δ a two-consumer sweep, not "1 day"**

**PR 10 · C2 + C4 price lists and customer groups**
- **Δ Migration A (`prices`), in this order, every step guarded so a half-run re-runs:** (0) **dedupe** rows equal on `(priceable_type, priceable_id, currency, minimum_quantity)` that differ only by `price_list_id` — seeders write the default list, the admin repeater writes `NULL`, so the same product can legally hold both today; **keep the non-null-list row** (that is the one `ResolvePrice` returns today, so the storefront price cannot change), delete the rest, log ids; (1) `dropForeign(['price_list_id'])` — **by column array, not name**: SQLite's grammar throws on a named drop, MySQL derives the same default name `prices_price_list_id_foreign`; (2) add unique `prices_priceable_currency_quantity_unique` on the four columns; (3) drop `prices_priceable_list_currency_quantity_unique`; (4) `dropColumn('price_list_id')`; (5) `dropIfExists('price_lists')`. **SQLite branch:** native `DROP COLUMN` refuses FK columns → drop-and-recreate `prices` in final shape (safe: every SQLite environment migrates fresh). Test: seed one collision, run the migration, assert `priceFor()` returns the same amount before and after.
- Migration C: `dropForeign(['customer_group_id'])` → `dropColumn` → `dropIfExists('customer_groups')`.
- Package deletions: `PriceList` + factory, `DefaultPriceListResolver` + binding, `Pricing::{defaultPriceList,priceList}()`, `priceListSelect()`, the two-pass fallback in `ResolvePrice`, `priceList` params on `HasPrices::priceFor`, `InteractsWithPrices`, `CalculateTotal`, `RepriceCart`; `PricingLogSubscriber:112` context key; `CustomerGroup` + factory + resource + pages + lang, `Commerce::customerGroup()`, `Customer::group()`, `EditCustomer` preservation.
- **Δ Consumer work — IOW:** 10 `defaultPriceList()` calls in 9 files (`Shop/ShowBundleController:88`, `ShowArticleController:139`, `ProductCardResource:23`, `PurchasableDetailResource:65`, `BundleCardResource:23`, `Checkout/Steps/CalculateTotals:28`, `Modules/RelatedProducts:39`, `InteractsWithCatalogueSale:43,60`, `ListingSort:109`) + docblocks (`PriceView:14`, `AppServiceProvider:70`); `Bundle::componentsTotal(Currency, ?PriceList)` signature; seeder (5 sites); `CustomerGroupPolicy` + `AppServiceProvider` policy map (3 lines) + `PermissionPolicyTest` row; **12 test files** (`CheckoutControllerTest`, `CheckoutVoucherTest`, `CartApiTest`, `ResolveTaxRateForOrderTest`, `BundleReservationTest`, `ShopControllerTest`, `CheckoutQuoteParityTest`, + the 5 from PR 5 if any residue).
- **Δ Consumer work — bianka:** 9 calls in 9 files (`ShowProductController:129`, `ShowBundleController:44`, `ListProductsController:181`, `HomeController:118`, `ValidateCart:92` + named-arg `:77`, `CalculateTotals:87` + `:52`, `BundleForm:128`, `Bundle:171`, `Product:196`); `PriceList` type in `Bundle:22,218`, `DetailPayload:12,25,126`, `CatalogCard:11,22`; `config/pricing.php:5,36` and `config/commerce.php:7,28` (edit the lines — **these files are not pure restatements**); `AppServiceProvider:25,92` policy loop; `DemoCatalogSeeder` (14 `price_list` hits); **19 test files** (list in the critique record; `ResponsiveImagePayloadTest`, `ShowBundleControllerTest`, 5 checkout, 3 cart, 3 actions, `AddToCartTest`, `PaymentPayableGuardTest`, `BundleComponentsTotalTest`, 2 payment).
- Gate: both seeder tests green; Migration A run on MySQL 8 with the collision case present.

**PR 11 · C3 Stripe profiles + `InitiatePayment`**
- Delete `InitiatePayment`, `PaymentProfile` + factory, `HasPaymentProfiles`/`InteractsWithPaymentProfiles`, `ManagesCustomers`, `PaymentCustomerData`, `Payment::paymentProfile()`, `StripePaymentGateway::{createCustomer,customerDashboardUrl}`, `PaymentGateway::customerDashboardUrl()`, the `FakePaymentGateway` counterparts, `Customer implements HasPaymentProfiles`, `tests/Stubs/migrations/…create_test_profileables_table.php`. **Keep `InitiatePaymentResult`.**
- Migration: `dropIfExists('payment_profiles')` (no FK).
- **Δ Consumer work:** IOW `CheckoutControllerTest` anonymous gateway drops `customerDashboardUrl`; bianka `config/payment.php:5,73` (edit lines), `docs/go-live-data-purge.md:94`, the two docblocks naming `InitiatePayment`.
- **Δ Release 3 also deletes the `ShipmentStatus::Ready` enum case** (step two of PR 9).

## 5. Novel conventions this brief introduces

1. **Forward drop migrations.** No package migration drops a table in `up()` today. Delete the create files; ship one forward migration per feature with guarded `dropIfExists` in `up()`, empty `down()`. Verified safe: the migrator skips missing files on rollback, `migrate:status` lists files only, bianka boots with `migrate --force` (never `fresh`). **Δ** The migration also proves the morph-alias precondition before removing an alias (PR 4).
2. **`{key}.enabled` gate via `bootDomain()`.** **Δ** Base `boot()` keeps only config-independent steps; everything else, including child extras, lives in `bootDomain()`. `DomainServiceProvider` domains only.
3. **Audit events from model hooks** (X1) — first domain where the audit signal is dispatched by the model, so every write path is covered. The events rule (PR 1) admits it under clause (c).
4. **Δ Package-scheduled tripwire + drift event** (S16): the package schedules its own reconcile under the existing `schedule.enabled` switch and dispatches a drift event; consumers alert on it.

## 6. What the adversarial pass settled (draft-1 questions)

| Q | Outcome |
|---|---|
| 1 Drop-migration shape | Survives, with drop order (pivots before `variants`; FK before parent table) and the MySQL rule extended to release 2 |
| 2 Gate scope | Survives, mechanism changed to `bootDomain()`; scoped to `DomainServiceProvider` domains; `currency.enabled` untouched |
| 3 Shipment trigger | **Flipped** to `OrderStatusChanged(Confirmed)` — both reviewers independently |
| 4 Pricing via model hooks | Survives; past-date guard only when dirty, orphan date normalised not thrown, rules shipped in the schema, seeder actor set |
| 5 `Ready` | Drop the state (two-step); **keep** the two typed problem actions; add `problem_reason`. IOW staging has `ready` rows; whether bianka production has any is still unknown |
| 6 Cadence | Three releases; release 3 is a two-consumer sweep, not a day |
| 7 `ToolRegistry` | Survives; contract stays static |

**Still open for Jelte:** (d) **Δ from Release 1:** the package's shipped defaults `commerce.order.abandon_after_minutes = 60` vs `inventory.reservation_ttl = 30` violate the very invariant X6's comment now states — a fresh consumer taking both defaults releases stock while the gateway intent is live, on every checkout. Both current consumers override the TTL to 90 so no live shop is exposed. Changing a default is behaviour: lean = raise the reservation TTL default to 120 in release 2 and add a boot-time assertion that abandon > TTL. (a) whether Bianka uses the ready-then-dispatch two-step deliberately — production has zero `ready` rows, so the data step is a no-op either way; if she wants the two-step, PR 9 drops out; (b) whether `InventoryDriftDetected` + a consumer alert listener is in scope now or a follow-up (lean: event now, one class; listeners when each shop wants one); (c) whether the PR 6 orphan cleanup should also delete the *orders'* stale reservations or leave that to the existing expiry path (lean: shipments only — reservations already have an owner).

## 7. Disposition manifest

| Item | PR | Disposition | Note |
|---|---|---|---|
| Convention rewrites | 1 | **done** (#24) | gate rule carries a "not implemented until PR 7" marker; CLAUDE.md dep-graph lines fixed in the PRs that falsified them |
| `priceFor()`-only architecture test | — | **deferred** | no package-side way to test consumers; consumer test each, later |
| C7 relation managers ×6 | 2 | **done** (#25) | 4 tests deleted, rules covered elsewhere |
| C8 Media dead actions/events | 2 | **done** (#25) | |
| C9 Customer actions/events | 2 | **done** (#25) | incl. the package Filament page overrides |
| C10 `ListCategoryBrowsables` | 2 | **done** (#25) | |
| C11 `ResolveShippingZoneForAddress` | 2 | **done** (#25) | Shipping→Location edge gone |
| C14 `PaymentStatus::Expired`, `isExpired/isResolved/isLowStock`, `Address::oneLine()`, `Currency::symbol()`, `CustomerFactory::forGroup()` | 2 | **done** (#25) | `Expired`: production count = 0 on both shops (2026-09-07), tag gate clear |
| C14 `HasStock::stockMovements()` | 2 | **dropped** | ledger assertion helper in `ReceiveItemsTest:64`; the audit's "0 callers" ignored `tests/` |
| C14 `HasLocaleGroup::inLocale()` + `forLocale/monolingual` scopes | 2 | **dropped** | live in IOW `ContentLocaleGroupTest:62-84`; only `locale()` is truly dead — opportunistic, release 2 |
| C14 `setTranslations()` | 2 | **dropped** | test fixture helper in ~20 package tests; not worth the churn |
| C14 `Refund::actor()` | 2 | **dropped** | has its own model test (`RefundModelTest:84-92`) — tested API, not dead |
| C14 `OrderFactory::status()` | 2 | **dropped** | factory state used by 2 test files |
| C13 `FakePaymentGateway` → tests | — | **dropped** | consumed by both consumers' suites; audit premise false |
| D13 Storefront→Media | 2 | **done** (#25) | |
| D14 `HasAvailability` | 2 → **8** | **moved to release 2** | IOW `app/Contracts/Purchasable.php:15` extends it — one consumer line, goes with PR 8's consumer edits |
| D16 Media system-actor imports | 2 | **done** (#25) | |
| D18 `default_currency` home | 2 | **done** (#25) | `currency.default` had to be **created**; bianka `config/commerce.php:82` restates the old key (same value) — delete on bump |
| D19 `requireMorphMap()` | 2 | **done** (#25) | now fires from Support (first provider) — strictly earlier |
| C22 `ToolRegistry` | 2 | **done** (#25) | contract stays static |
| S6 unread media eager load | 2 | **done** (#25) | |
| S1+S2 translations `$with` | 3 | **done** (#26) | query-count test verified failing at 3 queries without `$with` |
| X4 fallback locale in Storefront | 3 | **done** (#26) | `whereIn([current, fallback])`; test verified failing (`null`) against the old `where` |
| S3 orders `withSum` | 3 | **done** (#26) | **brief's line was a no-op** — `withSum` yields `NULL` for no refunds so `??` fell through to the per-row query on nearly every row; keyed on `array_key_exists('refunds_sum_amount', getAttributes())` instead. New `OrderResourceRefundColumnTest` with a minimal `HasTable` host — first Filament table harness in the suite |
| S7 cart log handlers | 3 | **done** (#26) | |
| S8 FlowChain Info rows | 3 | **done** (#26) | |
| S16 schedule `inventory:reconcile` + drift event | 3 | **done** (#26) | `inventory.schedule.reconcile` cron, `InventoryDriftDetected` dispatched from the action on a dirty report; 4 tests |
| D2 `imagewebp` guard + `ext-exif` | 3 | **done** (#26) | |
| D4 Stripe `^20` | 3 | **done** (#26) | suite green on 20.3.1, no source change; `composer.lock` is gitignored so it resolves on next install |
| X6 inverted TTL comment | 3 | **done** (#26) | **but the shipped defaults violate the invariant** (abandon 60 min vs reservation TTL 30 min) — see §6 open (d) |
| C5 Variants `Variant` half | 4 | pending | alias-precondition migration |
| X1 pricing write path | 5 | pending | + IOW 5 test files |
| X2 shipment timing | 6 | pending | `OrderStatusChanged`; 6 tests; orphan cleanup; IOW `CheckoutControllerTest` |
| X7 Commerce README drift | 6 | pending | free rider |
| D7/D8 domain gate | 7 | pending | `bootDomain()` refactor across all child providers |
| D10 Tax string | 8 | pending | 2 steps + 2 tests |
| D12 `tax_category` cast | 8 | pending | |
| C23 `Ready` step 1 | 9 | pending | production `ready` rows = 0 on both shops (2026-09-07); IOW `ListOrdersController:88` |
| C23 `Ready` step 2 (enum case) | 11 | pending | |
| C2 price lists | 10 | pending | dedupe keeps list row; MySQL-tested with collision |
| C4 customer groups | 10 | pending | IOW policy + bianka policy loop |
| C3 Stripe profiles | 11 | pending | `InitiatePaymentResult` stays |

**Acceptance:** Jelte reviews each PR; package suite + both consumer suites + both seeder tests green before each tag; MySQL run recorded for every DDL migration in releases 2–3; both consumers bumped and three periphery docs current before a release is called done; no `pending` left in this table.

## 8. Rough effort — **Δ revised**

Release 1 ≈ 1 day. Release 2 ≈ 3 days (the `bootDomain()` refactor across every child provider, six listener tests, and the consumer test rewrites are the bulk). Release 3 ≈ 2 days (19 call sites + 27 test files + two seeders + the collision-safe migration on MySQL). About six working days end to end.

## 9. Release 1 close-out (2026-09-07)

Three PRs open, stacked #25 → #26 → #24; suite 1297 → 1294 (#25) → 1303 (#26). No consumer edited, nothing tagged.

**Where the brief was wrong, recorded so the pattern stops:** five C14 symbols and D14 had callers the audit's "0 callers" missed — four in the package's own `tests/`, two in IOW. The audit grepped `src/` and consumer `app/`; every future "dead" claim greps `tests/` too. PR 2 and PR 3 were not file-disjoint (`ResolvesEagerLoading.php`). "Two `AdminNavigationLabelsTest` assertions" were six. S3's prescribed line would have shipped doing nothing.

**Pre-tag gate — taken 2026-09-07 via SSH + `docker exec` on both app containers:** `payments.status = 'expired'` = **0** (IOW) / **0** (Mayangna); `shipments.status = 'ready'` = **0** / **0**. Tag gate clear; release 2's data step is a no-op on both.

**Consumer bump for v0.71.0 (both, same sitting):** `^0.71.0`; periphery status lines (bianka's says v0.68.0); periphery external-surface: `InventoryDriftDetected`, the two schedule entries, the removed cart/FlowChain handlers; bianka: delete `config/commerce.php:82` (`default_currency`, now inert); IOW: `routes/console.php:32` comment (cites inventory as the "consumer opts in" precedent, now inverted). Both suites green. Pushing a consumer's main deploys it.

**Carried forward from the Release 1 report:** `Cart::defaultCurrency()` duplicates `VariantsSchema::editingCurrency()` — becomes a `Currency::default()` accessor when PR 4 deletes the schema. The `OrderTableHost` harness in `OrderResourceRefundColumnTest` is the pattern for W1/X3 — promote to `tests/Support/` when a second user appears. `PaymentsRelationManager`/`ShipmentsRelationManager` remain outside the default-deny base (periphery "known gap", now 3 managers not 8).
