# Complexity audit — in-other-shops — 2026-09-07

Scope: every PHP file under `src/` (682 files, 37,018 lines) at commit `52a8a71` (v0.70.0), read by five parallel reviewers (generic leaves · middle band · Commerce · Shipping/Purchasing/Tracking · Storefront/Variants/Agent + dependencies). Extension points were cross-checked against both consumers (`in-other-worlds`, `bianka-shop-one`: `app/ config/ routes/ tests/ database/`). Headline claims were re-verified by grep before this document was written.

Brief: find unnecessary complexity, code that won't scale, reinvented wheels, and unnecessary dependencies. CLAUDE.md conventions were deliberately **not** used as a yardstick; §6 reports which conventions produced which findings so they can be reconsidered.

Confidence: **H** = verified by grep/read, **M** = judgement call on evidence, **L** = plausible, small.

---

## 1. Summary

The money path is in good shape and nothing here needs a library it doesn't have. The problems are almost all *dead or single-consumer machinery kept alive by convention*:

| Theme | Rough size |
|---|---|
| Whole features nobody uses (price lists, customer groups, Stripe customer profiles, B2B tax mode, `Variant` half of Variants, 6 unmounted relation managers, dead Media/Customer actions+events) | ~3,300 lines + 5 tables |
| Convention overhead with zero consumers (config-driven model registries: ~30 keys, 0 overrides; ~40 events whose only listener is the logger; `Has*` contracts nothing type-hints) | ~1,200 lines |
| Infrastructure larger than the thing it serves (Taxonomy counter cache; FlowChain publish/validate layer; Logging dispatcher layer that duplicates Monolog stacks) | ~2,100 lines |
| Domains migrated into a consumer that never writes them (Purchasing, Tracking, Variants in bianka; Variants in IOW) | 3 domains, 8 tables |
| Real query-shape problems | 4 (two N+1s, one per-row SUM, one whole-catalog hydration) |
| External deps to drop | 1 candidate (`opgginc/laravel-mcp-server`, if `laravel/mcp` is adopted) |

Roughly **6,000–7,000 lines (16–19% of `src/`)** are removable or movable without changing anything either consumer does today. Bucket 3 (reinvented wheels) is mostly *clean*: Currency, StateTransitions, DomainServiceProvider, the Payment webhook path, Inventory locking and the Translation model all earn their lines, and the honest library comparisons (spatie activitylog, medialibrary, translatable, model-states, cashier, brick/money, package-tools) come out *larger* than what exists.

Correctness issues found on the way are collected in §5 — two of them matter (pricing audit bypass, shipment created before payment).

---

## 2. Unnecessary complexity

### 2.1 Whole features with no consumer (delete)

| # | What | Where | Evidence | Size | Conf |
|---|---|---|---|---|---|
| C1 | **Taxonomy `category_morph_counts` counter cache** — 4 events, observer, `MaintainCategoryCounts` (per-driver raw upsert SQL), `CategoryAncestry`, reconcile + recompute commands (hand-rolled `GET_LOCK`/pg advisory), `SyncCategories` (exists only to route through events), `TaxonomySchema::saveRelationshipsUsing` override, `CategoriesRelationManager` bulk-detach override, `Category::save()/delete()` overridden to open transactions | `src/Taxonomy/{Listeners,Observers,Support,Actions,Commands,Events}` | The truth function `CategoryCountAggregator::expected()` (`Support/CategoryCountAggregator.php:23-47`) is **already** a two-query read (one `GROUP BY` over `categorizables` + parent map). Any raw `->attach()/sync()` drifts the cache silently; IOW has 4 raw attach sites (`app/Project/AgentTools/CreateContentDraft.php:197`, `AttachTagToContent.php:106`); audit findings B-2…B-7 were all cache bugs; it emptied the live ToC once | ~830 lines core, ~900 net incl. overrides; 2 artisan commands; 1 table | H |
| C2 | **Price lists** — `PriceList` model/migration/factory, `DefaultPriceListResolver`, two-pass fallback in `ResolvePrice`, `priceListSelect()` | `src/Pricing` | No `PriceListResource` exists; `priceListSelect()` 0 callers; both seeders create exactly one list; admin repeater writes `price_list_id = NULL` so every resolve misses first then falls back; 14 consumer call sites thread `Pricing::defaultPriceList()` while `Storefront/Resources/BrowsableResource.php:47` doesn't | ~220 pkg + 14 consumer sites | H — **DECIDED 2026-09-07: delete** (see §8 note) |
| C3 | **Stripe customer profiles + `InitiatePayment`** — `PaymentProfile` model/table, `HasPaymentProfiles`/`InteractsWithPaymentProfiles`, `ManagesCustomers`, `PaymentCustomerData`, `customerDashboardUrl()` forced on every gateway, `Customer implements HasPaymentProfiles` | `src/Payment`, `src/Commerce/Customer/Models/Customer.php:19-23` | `InitiatePayment` 0 production callers (both consumers' `RecordPendingPayment` docblocks say they deliberately use `CreatePendingPayment`); `paymentProfileFor()`/`customerDashboardUrl()` 0 callers | ~285 lines + 1 table | H |
| C4 | **CustomerGroup** — model, migration + FK on customers, factory, resource + 3 pages, lang, `$customerGroupId` param on Create/UpdateCustomer, preservation dance in `EditCustomer` | `src/Commerce/Customer`, `src/Commerce/Filament/Resources/CustomerGroupResource.php` | README:66 "nothing reads the group yet"; neither consumer registers the resource; `CustomerFactory::forGroup()` 0 callers | ~220 lines + 1 table | H — **DECIDED 2026-09-07: delete** (see §8 note) |
| C5 | **`Variant` half of Variants** — `Variant` model, 4 actions, `HasVariants`/`InteractsWithVariants`, `VariantsSchema`, 2 events, 2 exceptions, factory, lang, 3 migrations | `src/Variants` | 0 references in either consumer to anything but `Option`/`OptionValue`/`OptionResource` (bianka's "Family axis"); IOW references nothing from the domain at all; 3 empty tables (`variants`, `option_value_variant`, `optionables`) migrated in both apps. Bianka's "Variants → Families" retirement never reached the package | ~955 src + 883 test lines + 3 tables | H |
| C6 | **B2B `TaxMode` seam** — enum, `PricingConfig`, config block, `CalculateTotal` param whose only runtime effect is `throw not implemented`, `CalculateTax` | `src/Pricing` | `CalculateTax` 0 production callers; bianka carries `config/pricing.php` to restate `'inclusive'` | ~100 lines | M |
| C7 | **Six unmounted Filament relation managers** — `PricesRelationManager`, `CategoriesRelationManager`, `TagsRelationManager`, `OrderLinesRelationManager`, `OrderAddressesRelationManager`, `MediaRelationManager` | `src/{Pricing,Taxonomy,Commerce,Media}/Filament/RelationManagers` | Registered by no package Resource and no consumer; only self-tests + label assertions. (`PaymentsRelationManager`, `ShipmentsRelationManager` ARE used) | ~780 lines | H |
| C8 | **Dead Media actions/events** — `StoreMedia`, `DeleteMedia`, `MediaStored`, `MediaDeleted`; `Mediable::isImage()/url()` | `src/Media/{Actions,Events}` | 0 callers, 0 listeners anywhere | ~130 lines (370 with C7's MediaRelationManager) | H |
| C9 | **Dead Customer actions/events** — `CreateCustomer`/`UpdateCustomer` are 30-line wrappers whose only added value is `CustomerCreated`/`CustomerUpdated`; both consumers call `Commerce::customer()::query()->create/firstOrCreate` directly | `src/Commerce/Customer/Actions` | 0 listeners, not even the log subscriber | ~90 lines | H |
| C10 | **Storefront `ListCategoryBrowsables`** — loads every browsable across every model, sorts in PHP, paginates the array | `src/Storefront/Actions/ListCategoryBrowsables.php` | 0 callers anywhere (also the only whole-table load in the tier) | 90 lines | H |
| C11 | **Shipping `ResolveShippingZoneForAddress`** | `src/Shipping/Actions/ResolveShippingZoneForAddress.php` | 0 callers; sole reason Shipping imports `Location\Address` | 20 lines + 1 domain edge | H |
| C12 | **`SetPanelLocale` middleware + `config/shops.php`** | `src/Support/Http/Middleware`, `src/Support/config/shops.php` | Registered by 0 consumers; `shops.admin_locale` read only by the middleware. ⚠ Contradicts memory that admin i18n shipped with bianka=Spanish — check which is true | ~55 lines | M |
| C13 | **`FakePaymentGateway` in production autoload** | `src/Payment/Testing/FakePaymentGateway.php` | 300 lines used only by 6 package tests; IOW writes an anonymous gateway, bianka uses Mockery. Move to `tests/` | 300 lines moved | M |
| C14 | Small dead API: `PaymentStatus::Expired` (never assigned); `stockMovements()`, `isExpired()`, `isResolved()`, `isLowStock()`; `InteractsWithLocaleGroup::inLocale/locale/scopeForLocale/scopeMonolingual`, `setTranslations()`; `Refund::actor()`; `OrderFactory::status()`, `CustomerFactory::forGroup()`; `Currency::symbol()`; `Address::oneLine()` | various | all 0 callers across three repos | ~100 lines | H |

### 2.2 Convention overhead with zero consumers

| # | What | Evidence | Size | Conf |
|---|---|---|---|---|
| C15 | **Config-driven model registries** — every domain's `models.*` keys + `{Domain}::model()` facade + `modelName()` on every factory + `Commerce::x()::query()` at every call site | **~30 keys package-wide, 0 overrides in either consumer.** Bianka restates defaults; IOW `config/media.php:4-5` imports the package's own classes and points the hook back at them. Kills static types at every relation | ~400+ lines of plumbing (Commerce 130, middle band 139, leaves ~60, shop core ~120) | H |
| C16 | **Events whose only listener is the logger** — Shipping 5/6, Purchasing 4/4, Pricing 6/6, Inventory 6/6, Taxonomy Tag 2/2, FlowChain `StepCompleted` (0 listeners, fires per step per run), Cart 3/3, Agent 2/2, Variants 2/2, Media 2 (0 listeners), Customer 2 (0 listeners) | ~40 event classes; the genuinely consumed ones are Payment's 3, `OrderCreated`/`OrderStatusChanged`, `ShipmentDispatched`, `StockAdjusted`-family in consumers. Hazard: `Event::fake()` in a consumer test silently disables the audit row | ~500 lines (events + subscriber handlers) | H |
| C17 | **`Has*` contracts nothing type-hints** — `HasShipment` (1 impl, 0 hints), `HasCustomer` (0 hints in src), `InteractsWithOrders` (0 users; both consumers implement `HasOrders` without it), `HasAddresses` 1-method pair, `HasAvailability` = byte-identical duplicate of `HasStock` (Storefront keys availability off one, eager-loading off the other) | | ~120 lines | H |
| C18 | **FlowChain publish/discover** — `FlowChainRegistry` file-exists probe, `flowchain:publish` (regex-copies source), `flowchain:list`, `flowchain:verify-tests`, `BrokenPublishedChain` | Exactly **one** `PublishableFlowChain` exists (`AddToCartChain`). Both consumers published it; each published copy is a subclass overriding `steps()` — a config key (`commerce.add_to_cart_chain`) delivers the same thing via the mechanism every model override already uses | ~450 lines | H |
| C19 | **FlowChain contract validator + fingerprint** — `ChainContractValidator`, `FlowStepFingerprint`, `FlowChainContractViolation`, `flowchain:check-contracts`, `expectedInputs()/producedOutputs()/version()` on `FlowStep` | Types are free-form strings compared with `!==`; runs only in the artisan command; neither consumer runs it (CI, composer scripts, hooks: 0); `version()` overridden 0 times; declared on 7 of 21 steps. The payload is already a typed class | ~280 lines | H |
| C20 | **Logging dispatcher/handler layer** — `LogDispatcher` → `LogEntry` (allocated twice) → handler map → `FilteredLogHandler` → `DatabaseLogHandler`/`FileLogHandler` → `Log::channel()` → Monolog: 8 classes between event and row | Implements exactly what Laravel `stack` channels + a `monolog` driver channel + Monolog per-handler `level` already do. IOW already wrote `app/Logging/DomainLogBridgeHandler.php` — a Monolog handler feeding `DatabaseLogHandler` — so two routing systems feed one table. `FilteredLogHandler` 0 users; `LogContext::set()/all()` 0 callers; `Enums/LogLevel` duplicates `Psr\Log\LogLevel`. Keep `LogActor`, `LogContext` actor half, `RunsAsSystemActor`, subscribers, prune command | ~350 pkg lines + both consumers' `domain-log.php` (78 + 111) | H |
| C21 | **Storefront Actions/Resources exist only for the Agent** — consumers use only `HasStorefrontPresence` + trait (57 lines); `ListBrowsables` takes an HTTP `Request` so the only callers (4 Agent tools) fabricate `Request::create('/', 'GET', …)`; `StorefrontContext` one-field DTO bound non-singleton, resolved per item per page | | ~450 lines to move under Agent or simplify | M |
| C22 | **Agent scaffolding** — `ToolRegistry` eagerly `app->make()`s all 35 tools (11 pkg + 24 IOW) on every request via `app.booted`, to fill a list nothing reads (`all()`/`find()` 0 callers in src); `AgentToolContract` static-ness exists only for that; three invokable resolver classes for a `config()` lookup, used by DI in 2 tools and `new`'d inline in 5 | | ~130 lines; 35 container resolutions/request | H |
| C23 | **Shipping state machine** — 6 states incl. `Ready` and a reship loop; 5 typed `Mark*` actions wrapping `UpdateShipmentStatus`; 4 exceptions | `Mark*` called only from the package's own `ShipmentsRelationManager`; `Ready` forces two admin clicks per parcel and neither consumer distinguishes it from `Pending`; design doc: "mirrors Shopware's three-state-machine model"; Lost/Returned `reason` reaches only `domain_logs`, never the row | ~110 lines + 1 click per parcel | M |
| C24 | **Four classes to find/resolve the current cart** — `FindCart`, `ResolveCart`, `FindCurrentCart`, `ResolveCurrentCart` | 168 lines for "by owner, else by session token, optionally create" | ~120 lines | M |
| C25 | **`AddToCart` as a FlowChain** — facade + chain + payload + 3 steps + registry hop ≈ 370 lines for lookup / stock check / upsert / dispatch | Used: both consumers insert steps. But both insertions are one of two shapes (validate-before-write, react-after-write) that a throwable `CartItemAdding` + `CartItemAdded` would serve at ~40 lines. `metadata` bag read by nothing in the package | ~250 net | M — deliberate design, flag not fix |
| C26 | Misc one-liners: `ResolveShippingZoneForCountry` (DI wrapper over static; consumers use both spellings); `OrderNumberGenerator` interface + Container injection for one impl nobody swaps; `StockCast`/`Stock` DTO/`RawStockMutationException` (guards attribute assignment only, forced `AdjustStock` from atomic `increment()` to read-modify-write); `PurchaseReceiptReconciliationReport` third near-identical report DTO; per-domain exception bases with 0 typed catch sites (~15 classes; callers catch `\DomainException`) | | ~250 lines | M |

---

## 3. Won't scale (graded at 10× current)

| # | What | Where | Impact | Fix | Conf |
|---|---|---|---|---|---|
| S1 | **`ListCategoryTree` N+1 on category translations** | `src/Taxonomy/Actions/ListCategoryTree.php:74-84`; IOW `app/Http/Resources/CategoryResource.php:31-32` | 100–300 queries per categories page render | one `->with(['translations' => locale filter])` | H |
| S2 | **Package translatable models have no `$with = ['translations']`** — `Category`, `Tag`, `Option`, `OptionValue`; package `CategoryResource`/`TagResource`/`OptionResource` list columns lazy-load per row | `src/Taxonomy/Models`, `src/Variants/Models` | 1+N per admin page (Media already does it right) | 4 lines | H |
| S3 | **Orders admin table: one `SUM(refunds)` per row** | `src/Commerce/Filament/Resources/OrderResource.php:91-105` | 25–100 queries per page on the most-visited admin list | `withSum('refunds','amount')` | H |
| S4 | **Admin order-line picker hydrates the whole catalog, once per repeater row** | `src/Commerce/Filament/CommerceSchema.php:124-164, 245-249` (`->options()->preload()` over every `HasOrders` model `->with('translations')->get()`) | seconds per page at thousands of products; N× for N lines | `MorphToSelect` (W1) or `getSearchResultsUsing` | M |
| S5 | **Cart show: translations N+1 on translated catalogs** (bianka) | `src/Commerce/Cart/Http/Controllers/CartController.php:20` loads `items.cartable` but not translations | N+4 queries; carts ≤10 lines, so small — flagged because the same trap already 500'd the admin once | constrain morphTo eager load | H |
| S6 | Storefront listing eager-loads `media` that `BrowsableResource` never emits | `src/Storefront/Concerns/ResolvesEagerLoading.php:57` | 1 query + hydration per page for nothing | delete branch | H |
| S7 | Cart events write an audit row + a `COUNT` per mutation, guest carts included | `src/Commerce/Listeners/CommerceLogSubscriber.php:129-137` | highest-volume, lowest-value rows in `domain_logs` | drop 3 handlers | M |
| S8 | FlowChain `Started`/`Completed` logged at Info; IOW routes `flowchain` to the DB handler → 2 rows per add-to-cart and checkout | `src/FlowChain/Listeners/FlowChainLogSubscriber.php:32,39`; IOW `config/domain-log.php:35-36` | noise | log Failed only | H |
| S9 | `carts.expires_at` unindexed; `PruneExpiredCartsCommand` full-scans hourly (IOW) | migration `2026_03_10_000001` | small table; one-line index | | L |
| S10 | `GET /api/cart` uses `ResolveCurrentCart` (`firstOrCreate`) — the pattern `FindCart` was written to stop | `CartController::show` | bounded (consumers call it on drawer-open only) | use `FindCurrentCart` | L |
| S11 | `ReconcilePurchaseReceipts` `::query()->get()` whole table | `src/Purchasing/Actions/ReconcilePurchaseReceipts.php:30` | grows monotonically; one grouped join replaces it | | L |
| S12 | `PruneMediaCommand::nearestRow` O(files × rows) Carbon diffs | `src/Media/Commands/PruneMediaCommand.php:305-320` | 2k×2k = 4M diffs nightly | sort + binary search | L |
| S13 | `CategoryResource::applyTreeOrder` builds an N-clause `CASE` per admin page | `src/Taxonomy/Filament/Resources/CategoryResource.php:147-163` | fine at hundreds | | L |
| S14 | `ListBrowsables::hasColumn()` memoised per PHP process = per FPM request → up to 3 `information_schema` queries per searched listing | `src/Storefront/Actions/ListBrowsables.php:166-174` | cheap | contract method | L |
| S15 | Redundant indexes: `translations_locale_index` is a left-prefix of `translations_unique`; `addresses` explicit morph index duplicates `morphs()` | Translation/Location migrations | write amplification only | | L |
| S16 | `inventory:reconcile` is scheduled by **neither** consumer (IOW schedules the taxonomy tripwire only) | | infinite detection latency | package schedules its own tripwires | H |

Not problems (checked): cart totals (snapshot × qty, summed on read, `RepriceCart` as explicit refresh); Storefront listing (~10 queries for a 24-item page, size-independent); `domain_logs` (pruned daily, 90-day retention); `ExpireAbandonedOrders`; Inventory locking; Payment webhook path; `ShippingConfig` per-request DTO rebuild (microseconds).

---

## 4. Reinvented wheels

| # | Custom | Library | Verdict | Conf |
|---|---|---|---|---|
| W1 | `CommerceSchema` polymorphic product picker: two `Hidden` fields + `"{alias}:{id}"` string select + hydrate/update callbacks + full-catalog options (366 lines) | Filament 5 `MorphToSelect` with `Type::make(...)->getOptionLabelFromRecordUsing()->modifyOptionsQueryUsing()` | **Adopt**: −120 lines and S4 disappears | H |
| W2 | Logging dispatcher/handler layer (C20) | Laravel `stack` channels + `monolog` driver channel + Monolog processor for the actor | **Adopt**: ~350 pkg lines + both consumer `domain-log.php` collapse into `logging.php`. (spatie/activitylog is *not* the answer — model-diff-centric, no gateway/system/agent actor vocabulary) | H |
| W3 | Hand-rolled OAuth discovery (RFC 8414/9728), DCR (RFC 7591), `WWW-Authenticate` header, route deferral hack — ~375 lines, on top of `opgginc/laravel-mcp-server` of which the package uses 5 classes (transport + JSON-RPC + `ToolInterface`; **zero auth**) | `laravel/mcp` (first-party; `Mcp::oauthRoutes()` ships both `.well-known` docs + Passport-backed DCR + the header middleware; `Registrar::oauthRoutes()` skips its own routes when yours exist, so `CanonicalUrl` pinning can stay) | **Lean adopt, one release window, both consumers.** Cost: 11 pkg + 24 IOW tool classes reshaped (attributes + `schema()` + `handle(Request)`), mechanical. Keep: `AuthenticateAgent` (scope/admin-gate logic), `EnforceResourceParameter` (RFC 8707, no equivalent), DCR hardening (initial-access token, max_clients, throttle). Caveat: laravel/mcp is at 1.0-beta; opgginc is stable but community-maintained | M |
| W4 | `MoneyFormatter` (56 lines, memoised `NumberFormatter`s) | `Illuminate\Support\Number::currency()` / `::percentage()` (package already uses `Number::fileSize`) | Adopt: ~50 → 2 calls | M |
| W5 | Raw GD in `GenerateImageVariants` + `ImageOrientation` (8-way orientation match, palette→truecolor, alpha) | `intervention/image` v3 (`Image::read()->orient()->scaleDown()->toWebp()`), GD stays as driver | Optional: −150 lines for +2 packages; GD is a defensible call at this size | L |
| W6 | ~350 lines of PHP adjacency-list walking (`CategoryAncestry`, `ListCategoriesInTreeOrder`, `ListCategoryAttachments`, `ListCategoryTree`), each with its own cycle guard | `staudenmeir/laravel-adjacency-list` (recursive CTE; `descendantsAndSelf()`, `tree()`, `orderByPath()`) — **not** kalnoy/nestedset | Optional: do C1 first, then decide; ~300 lines for one dep | M |
| W7 | Hand-rolled `GET_LOCK`/pg advisory lock (36 lines) + dual-driver upsert SQL | `Cache::lock()` (3 lines) | Disappears with C1 | H |
| — | `Support\StateTransitions` + `Transitionable` | spatie/laravel-model-states | **Keep** — library is far heavier for 3–6-value enums | H |
| — | Translation EAV + locale groups | spatie/laravel-translatable (JSON column) | **Keep** — right library for greenfield, but 14 translatable models + live data migration on bianka, and locale-group rows (own slug/media/price per locale, shared inventory) are orthogonal and stay regardless | H |
| — | Media (many-to-many `mediables` with collection/position/is_cover, External/Embed types, per-locale alt via translations) | spatie/laravel-medialibrary | **Keep** — library models media as belonging to one model; ~1,050 of 3,294 lines overlap but adoption is a schema re-model | H |
| — | `DomainServiceProvider` | spatie/laravel-package-tools (already transitively installed) | **Keep** — package-tools lacks morph-alias/log-subscriber/schedule hooks and assumes one package = one provider | H |
| — | Payment gateway abstraction / webhook ledger | laravel/cashier | **Keep** — cashier is Stripe-only, Billable-user-centric, no idempotency ledger, no polymorphic payable | H |
| — | Integer-cent arithmetic, `LargestRemainderAllocator` | brick/money | **Keep** — adds a dependency without removing lines | H |
| — | `PaymentGatewayManager` (68 lines) | `Illuminate\Support\Manager` | **Keep** — the custom one is smaller | M |
| — | `ApplyVoucherController` inline `RateLimiter` | `throttle:` middleware | **Keep** — clear-on-success + redirect-with-error aren't middleware behaviours | L |
| — | Slugs (3-line `Str::slug`) | spatie/laravel-sluggable | **Keep** | H |

---

## 5. Dependencies

### External
| # | Dep | Finding | Conf |
|---|---|---|---|
| D1 | `opgginc/laravel-mcp-server` (12,028 vendor lines) | Package uses 5 classes; prompts/resources/swagger converter/9 commands/REST API unused; ships no auth. Drop if W3 lands | H |
| D2 | `ext-gd` | Used directly (~30 calls, 2 files). Keep. Gaps: `imagewebp` called unguarded (GD without WebP has no such function); `exif_read_data` used behind `function_exists` but `ext-exif` is neither required nor suggested → a build without it silently skips orientation | H |
| D3 | `ext-intl` | Used (`NumberFormatter`, `Locale::getDisplayRegion`, `Collator`); `Number::currency` needs it too. Keep | H |
| D4 | `stripe/stripe-php` | Correctly guarded (`class_exists` in provider boot, lazy factory). **Constraint is stale**: package says `^15 \|\| ^16`, both consumers pin `^20` — the package suite tests a major the consumers never run | H |
| D5 | `laravel/passport` | Guarded by config not class: with OAuth on and Passport absent, `POST /oauth/register` fatals at request time. One `class_exists(ClientRepository::class)` in `CanonicalUrl::assertConfiguredForOauth()` would fail fast. IOW requires it directly; bianka has OAuth off | L |
| D6 | `filament/filament` | 0 non-`Filament/` files import Filament (68 files / 6,337 lines / 17% of src live under `*/Filament/`). Could be `suggest`; both consumers use the admin so it's tier hygiene, not savings | M |

### Domains migrated into consumers that never write them
| # | Domain | bianka | IOW | Remedy |
|---|---|---|---|---|
| D7 | **Purchasing** (3 tables, subscriber, command) | 0 references (comments + log-config only) | admin chrome on a pre-purchasable shop; reconcile deliberately unscheduled | `purchasing.enabled` boot gate (memory: config-default-true migrations gate is fine) |
| D8 | **Tracking** (2 tables FK'ing into Commerce) | 0 writes, 0 reads; `ProcessCheckout.php:38` explicitly declines the snapshot step; package README wrongly claims bianka ships a widget | read by `ProductAttributionReport` MCP tool; Umami cannot join to order revenue, so keep | gate off for bianka; fix README |
| D9 | **Variants** `Variant` half (3 tables) | unused | unused | delete (C5) |

### Internal edges for one call
| # | Edge | Verdict |
|---|---|---|
| D10 | Tax → Location: `ResolveTaxRate(Address)` reads only `country_code`; both consumers build a throwaway `Address` model to call it | take `string $countryCode`; Tax loses its only dependency |
| D11 | Shipping → Location: only via C11 | delete with C11 |
| D12 | Purchasing → Tax: `tax_category` cast on `PurchaseOrderLine`, no writer ever sets it (always null) | drop cast + import |
| D13 | Storefront → Media: eager-loads media nobody emits (S6) | delete |
| D14 | Storefront → Inventory: `HasStock` in eager loader while `HasAvailability` duplicates it for the resource (C17) | keep `HasStock`, delete the duplicate |
| D15 | Support → Currency (`MoneyFields` → `DisplayLocale`, `MoneyFormatter`): tier-0 depending upward | move `MoneyFields` to `Currency/Filament/` |
| D16 | Media commands → Logging `RunsAsSystemActor`: actor set, but every write is `saveQuietly()` and no event fires, so it's never read | drop both imports |
| D17 | Variants → Commerce (`HasCart` on dead `Variant`) | goes with C5 |
| D18 | Variants reads `commerce.cart.api.default_currency` — a shop-wide default currency under `cart.api` is the wrong home | `currency.default` |
| D19 | `CurrencyServiceProvider::boot` calls `Relation::requireMorphMap()` — a global side effect hidden in a leaf provider | move to `SupportServiceProvider` |
| — | Shipping ↔ Commerce is the package's only cycle (`ShipmentItem` → `Commerce::orderLine()`); accepted within shop core | — |

---

## 6. Correctness issues found on the way

| # | Issue | Where | Severity |
|---|---|---|---|
| X1 | **Pricing actions/DTO guards/events/audit log are bypassed by the only admin write path.** `PricingSchema::priceRepeater()` uses `Repeater::relationship()` and writes `prices` rows directly; only the unmounted `PricesRelationManager` and `Variants\CreateVariant` call `CreatePrice/UpdatePrice/DeletePrice`. **No price audit row is ever written for a price edited in either consumer's product admin**, and `PriceData`'s orphan-end-date guard never runs there. "Log pricing" is an explicit logging-scope decision | `src/Pricing/Filament/PricingSchema.php:25-37` | HIGH |
| X2 | **`CreateShipmentForNewOrder` fires on `OrderCreated`, which precedes payment in both checkout chains.** Bianka set `auto_create_shipment => false` and wrote its own on payment-succeeded; IOW keeps the default and special-cases "Pending shipment ≠ in transit". `ExpireAbandonedOrders` doesn't touch shipments and `shipments` has no FK to `orders` (morph), so every expired IOW checkout orphans a `Pending` shipment row | `src/Commerce/Order/Listeners/CreateShipmentForNewOrder.php` | HIGH |
| X3 | **Admin "create order by hand" bypasses every `CreateOrder` invariant** — no `tax_summary`, no lines↔subtotal reconciliation, no `OrderCreated` (so no shipment), no reservations, no voucher commit; totals accumulated as `(float)`. If nobody creates orders in the admin, dropping `create` from `OrderResource::getPages()` and making the lines tab read-only removes ~450 lines (W1 + totals logic) | `src/Commerce/Filament/Resources/OrderResource.php:276-315`, `CommerceSchema.php` | MEDIUM — **needs Jelte: do admins ever create orders by hand?** |
| X4 | Storefront eager-loads `translations` filtered to the current locale, so `findFallbackTranslation` searches the same filtered collection and never finds the fallback row: a product translated only in the fallback locale lists with `name = null` | `src/Storefront/Concerns/ResolvesEagerLoading.php:39,49,53`; `Translation/Concerns/InteractsWithTranslations.php:125-134` | MEDIUM |
| X5 | `CartResource::subtotal()` sums `effectiveUnitPrice` (snapshot → live → 0) while `Cart::subtotalCents()` sums the snapshot only — a line with `unit_price = null` gives a different subtotal in the API than in `QuoteCheckout` | `src/Commerce/Cart/Http/Resources/CartResource.php:37-46` vs `Cart.php:110-115` | LOW |
| X6 | `config/commerce.php:52-56` comment on `abandon_after_minutes` vs reservation TTL is inverted; bianka reported it upstream 2026-07-17 and pins the real invariant with `ReservationTtlInvariantTest` — still unfixed in the package | | LOW |
| X7 | Doc drift: Commerce README documents 7 order statuses (enum has 3), says cart API defaults on (config says off), documents a non-existent `OrderFailed` event; Tracking README claims bianka ships a widget; `media.allowed_mime_types` documented but absent from config | | LOW |
| X8 | `SetPanelLocale` is unregistered by both consumers and bianka's panel provider says "the admin stays English" — but memory says admin i18n shipped with bianka=Spanish. One of them is wrong | `src/Support/Http/Middleware/SetPanelLocale.php`; `bianka-shop-one/app/Providers/Filament/AdminPanelProvider.php:101` | verify |

---

## 7. Convention scorecard

Tally of which CLAUDE.md conventions produced findings, across all five reviewers, with a lean for the reconsideration pass.

| Convention | Flagged by | Findings | Cost seen | Lean |
|---|---|---|---|---|
| **Config-driven models** (`models.*` keys + `{Domain}::model()` registry + factory `modelName()`) | 5/5 | C15, C26 | ~30 keys, **0 overrides in either consumer** over six months and two shops; ~400 lines; static types lost at every relation | **Drop.** Models are non-final and container-rebindable; re-add a key per model the day a consumer subclasses one. Keep `$factory` statics + morph aliases |
| **Domain events on every state change** (`final readonly`, `::dispatch`) + **per-domain LogSubscriber** | 5/5 | C16, S7, S8, C9, C8 | ~40 events whose only listener is the logger; `Event::fake()` silently kills audit rows | **Split the rule.** Keep the subscriber as the audit seam (it's the right place for actor/channel). Emit an *event* only when a second listener exists or a brief names one; otherwise the action logs directly. The "explicit subscribe survives event:cache" argument applies equally to a direct call |
| **`Has*` contract + `InteractsWith*` trait per capability** | 4/5 | C17, C3 | ~120 lines of pairs nothing type-hints; one capability minted twice (`HasAvailability`/`HasStock`) | **Keep, tighten.** The rule already says "an empty trait is cargo-culting"; extend it: a contract nothing in the package type-hints is cargo-culting; one capability = one contract wherever it lives |
| **Symmetric structure / "every domain ships X"** (registry, exceptions base, report DTO, Actions/Resources/DTOs dirs) | 3/5 | C21, C26, exception bases | 30–50 lines/domain that exist "because the others have it"; Storefront has a full Actions/Resources surface for one caller family | **Symmetry follows consumers.** A domain whose public surface is a contract+trait is allowed to be just that |
| **FlowChain publish-and-modify** + **one step = one verb** + step contract docblocks | 2/5 | C18, C19, C25 | 1,560 lines for 1 published chain + 2 builder chains; 730 of them (publish/discover/validate) unused by anyone | **Keep the runner, drop the layer.** Resolve the chain by config key like every other override; delete the validator (the payload is a typed class). Open question, not a lean: whether AddToCart should be a chain or two events |
| **Filament Schema classes** | 2/5 | X1, W1, C7 | fragments are good (`MediaSchema` 9 users); but the pricing fragment bypasses the actions, the order fragment reimplements `MorphToSelect`, and 6 relation managers are unmounted | **Keep fragments; make them the documented write path** (route through actions or drop the actions). Delete unmounted relation managers |
| **Detection latency > prevention** (reconcile tripwires) | 2/5 | C1, S16 | two commands police a cache the package inflicted on itself; the inventory tripwire is scheduled by nobody | **A tripwire for a self-inflicted invariant is a smell — remove the invariant.** Where a tripwire stays, the package schedules it (`scheduleWhenEnabled` exists) |
| **`DomainServiceProvider` base** | 0/5 as a problem | D19, config copies | 14 users, genuine dedupe. Shallow `mergeConfigFrom` → 7 whole-file config copies across consumers to change one value | **Keep.** Deep-merge or document `Config::set` |
| **Factories ship with domain** (+ `Concerns/`) | 1/5 | C14 | dead states; a `Concerns/` dir for one 35-line helper used from one IOW file | **Keep**, prune |
| **Layered exception strategy** | 2/5 | C26 | ~15 classes, 0 typed catch sites (callers catch `\DomainException`); tests assert on leaves | Low stakes. Keep the leaves tests name; the per-domain base classes have no reader |
| **Migrations always loaded, no domain gate** | 2/5 | D7, D8, D9 | 8 tables + subscribers + commands in consumers that never write them | **Add `{domain}.enabled`** (default true) short-circuiting `boot()` — memory already rules a config-default-true gate fine |
| **Extensibility seams with zero consumers** (not a named convention — a habit: price lists, B2B tax mode, payment profiles, customer groups, `OrderNumberGenerator`, `metadata` bag) | 4/5 | C2, C3, C4, C6, C26 | ~830 lines + 3 tables | **Delete pre-launch.** Memory: single-release-window breaking changes are fine while both consumers are pre-launch |
| **Design-doc parity reflex** ("mirrors Shopware's three state machines") | 1/5 | C23 | an extra click per parcel, 110 lines | Model the states operators distinguish today; add `Ready` when a pick/pack step exists (additive enum case) |
| Domain invariants on the model; `StateTransitions`; verb families; `HasLabel`; `RunsLockedTransactions`; presenters | — | — | clean | keep |

---

## 8. Decisions needed from Jelte

### Decided 2026-09-07 — price lists (C2) and customer groups (C4) go, together

Discussed and agreed: both are stubs (a table plus a nullable FK), not features. They encode none of the decisions that make the feature expensive (which conditions select a price, how lists stack, net/gross display per group, shipping/payment availability per group, VAT-ID reverse charge, registration approval), and the `price_list_id`-on-`prices` shape pre-commits to "one price belongs to one list", which is not how rule-driven B2B pricing (Shopware's Rule Builder) works. Rebuilding when a real consumer needs it costs the design work either way; the tables are an afternoon. Delete in one release window, both consumers bumped.

**Intended future shape, so the intent survives the code:** customer group is the B2B seam. When a consumer needs it, build in this order: (1) `customer_groups` + FK on `customers`, assigned at registration/admin; (2) a price overlay resolved by *rule* (group, quantity, currency, date), not by list membership, layered on the base `prices` row; (3) per-group display mode (net/gross) as one switch in the total calculation; (4) per-group shipping/payment availability gates read from the existing config-driven zone/method tables.

**Guardrails — what the rest of the code must keep so this stays additive (the constraint Jelte set: never design the rest so it becomes impossible):**
- `ResolvePrice` stays the **single** price-resolution seam. Consumers call `priceFor()`, never query `prices` directly. A future overlay is one extra context parameter on one action; if consumers inline price queries that seam is lost. Worth an architecture test.
- `prices` keeps its unique index on `(priceable, currency, minimum_quantity)`; a future rule/group column is additive to it. Don't collapse `minimum_quantity` tiers — they are the existing per-row price dimension a group tier would sit next to.
- `Customer` stays a distinct model from the consumer's `User` (it is today; guest checkout needs it). The group FK lands on `customers`, never on `users`.
- Tax-inclusive vs. exclusive stays **one** decision point (`CalculateTotal`/`PriceBreakdown`) even after the B2B `TaxMode` stub (C6) is deleted — delete the enum and config, keep the single place a display mode would switch.
- `QuoteCheckout` remains the only path that turns a cart into charge/lines/threshold, so availability gates have one place to hook.

### Open

1. **X3** — do admins ever create orders by hand in Filament? Determines whether ~450 lines (order-line picker + float totals) go or get `MorphToSelect`.
2. **W3** — migrate Agent to `laravel/mcp` (beta, first-party, deletes ~375 lines of OAuth glue + the opgginc dep) or stay on opgginc?
3. **D7/D8** — gate Purchasing and Tracking off in bianka, or leave the dormant tables?
4. **C25** — is AddToCart-as-FlowChain worth its ~250-line premium over two events, given it's the *only* published chain? (Reviewers split; the mechanism works and both consumers use it.)
5. **X8** — which is true about admin locale: the memory entry or the code?
6. §7 leans — which conventions to rewrite in CLAUDE.md.

## 9. Suggested order of work

1. Correctness first: X1 (pricing audit bypass), X2 (shipment timing), S1/S2/S3 (four-line N+1 fixes), D4 (stripe constraint).
2. Deletions with zero blast radius: C7–C14, C10, C11, D12–D16, D19.
3. Feature deletions (one release window, both consumers bumped): C2, C3, C4, C5, C6.
4. Structural: C1 (counter cache → aggregator), C15 (registries), C16 (log-only events), C18/C19 (FlowChain layer), C20 (Logging → Monolog stacks), W1.
5. Gates: D7/D8 domain `enabled` flags.
6. Optional: W3 (laravel/mcp), W4–W6.

---

## 10. Cost × effect matrix (2026-09-07)

**Cost** = effort + blast radius: consumer code changes in both apps, migrations against bianka's deployed DB, test rewrites, live-connector re-verification. Cheap ≈ under half a day and no consumer code beyond a version bump. **Effect** = fixes a correctness bug, removes a query-shape problem, takes tables/domains out of a consumer, or deletes a whole concept. Structural tidying with no observable change counts as low effect however many lines it removes.

Where a finding is "stop doing this" versus "retrofit what exists", the two halves are listed separately — changing the rule is cheap, the retrofit usually isn't.

### Cheap and effective — do first, in this order

| # | Item | Why it's here |
|---|---|---|
| X1 | Route `PricingSchema::priceRepeater` through the pricing actions (or log from a `Price` observer) | audit correctness; explicit logging-scope decision currently silently unmet |
| X2 | Re-time `CreateShipmentForNewOrder` to payment-confirmed, or delete it | orphan Pending shipments on every expired IOW checkout; bianka's override disappears |
| S1, S2, S3, S6 | Four eager-load lines: category tree translations, `$with` on 4 package models, `withSum('refunds')`, drop the unread media load | 100–300 queries/page → 3; admin orders table 25–100 → 0 extra |
| D7, D8 | `{domain}.enabled` boot gate; bianka flips Purchasing + Tracking off | 5 tables, 2 subscribers, 1 command out of bianka's runtime |
| C5 | Delete the `Variant` half of Variants (keep Option/OptionValue/OptionResource) | 955 src + 883 test lines, 3 empty tables out of **both** consumers; zero references anywhere |
| C7, C8, C9, C10, C11, C14 | Delete: 6 unmounted relation managers, dead Media/Customer actions+events, `ListCategoryBrowsables`, `ResolveShippingZoneForAddress`, the small dead API | ~1,200 lines, zero blast radius |
| C2 + C4 | Price lists + customer groups (decided; §8 note) | double resolve pass gone; 14 consumer call sites simplify; 2 tables |
| C3 | Stripe profiles + `InitiatePayment` + `customerDashboardUrl` off the gateway contract | 285 lines, 1 table, one forced contract method every driver must implement |
| D10, D12, D13, D16, D19 | Tax takes a country string; drop the always-null `tax_category` cast; drop Storefront→Media; drop the unread system-actor imports; move `requireMorphMap()` to Support | 5 domain edges gone; consumers stop building throwaway `Address` models |
| S16 | Package schedules `inventory:reconcile` via `scheduleWhenEnabled` | a tripwire nobody wires has infinite detection latency |
| S7, S8 | Drop the three cart log handlers and FlowChain Started/Completed logging | highest-volume, lowest-value rows in `domain_logs` |
| D4, D2 | Stripe constraint `^20`; guard `imagewebp`; suggest `ext-exif` | one-line each; the package suite currently tests a Stripe major the consumers never run |
| C22 | Make `ToolRegistry::classes()` static; stop instantiating 35 tools per request | 25 lines; 35 container resolutions off every request |
| C13 | Move `FakePaymentGateway` to `tests/` | 300 lines out of production autoload |
| C23 | Drop `Ready` from the shipment lifecycle; fold Lost/Returned into one action with a stored reason | one fewer click per parcel for Bianka; reason becomes visible in admin |
| **Convention rewrites** (§7) | Stop adding `models.*` keys; emit an event only when a second listener exists; a contract nothing type-hints is cargo-culting; symmetry follows consumers; a tripwire for a self-inflicted invariant means remove the invariant; domains get an enable gate | prevents the next 1,000 lines of the same; costs a CLAUDE.md edit |

### Expensive and effective — schedule, one at a time

| # | Item | Cost | Why worth it |
|---|---|---|---|
| C1 | Taxonomy counter cache → `CategoryCountAggregator` at read time (+ `Cache::remember` if ever needed) | drop a table, delete 2 commands + observer + listener + 4 events + overrides, rewrite ~15 tests, IOW unschedules the tripwire | ~900 lines and a whole class of drift bugs (one live incident, seven audit findings) gone; the read path gets *simpler* |
| C18 + C19 | FlowChain: resolve the chain via a config key; delete publish/list/verify commands, registry probe, contract validator, fingerprint | both consumers' published `AddToCart` subclasses keep working; `flowchain:*` commands removed from periphery docs; ~10 tests | ~730 lines and a parallel resolution mechanism nobody else uses |
| X3 / W1 | Admin order path: **if admins never create orders by hand** → drop `create` page, lines tab read-only, delete `CommerceSchema` picker + float totals (~450 lines); **if they do** → `MorphToSelect` (−120 lines) | needs Jelte's answer; MorphToSelect path touches one schema | S4 disappears either way; the "if they do" path also removes float money arithmetic and a bypass of every `CreateOrder` invariant |
| C20 | Logging dispatcher → Laravel stack channels + Monolog processor for the actor; `DatabaseLogHandler` becomes the Monolog handler | both consumers' `domain-log.php` rewritten into `logging.php`; IOW's bridge handler deleted; periphery docs | removes a parallel routing system that IOW already had to bridge; ~350 pkg lines + 190 consumer lines. Convention change alone doesn't help here — it's a one-shot |
| W3 | Agent → `laravel/mcp`; drop opgginc + the hand-rolled OAuth discovery/DCR | 35 tool classes reshaped (mechanical); live connector at agent.inotherworlds.net must be re-verified end-to-end incl. Claude.ai OAuth; **laravel/mcp is 1.0-beta** | first-party, −12k-line dep, −375 lines of OAuth glue that has already needed several hardening passes. Do last; the beta is the risk |

### Cheap and ineffective — batch into whatever release touches the file, never a work item on their own

| # | Item | Note |
|---|---|---|
| X5, X6, X7 | `CartResource::subtotal` vs `Cart::subtotalCents`; inverted `abandon_after_minutes` comment; README drift (7 vs 3 order statuses, cart API default, `OrderFailed`, Tracking-bianka claim, `allowed_mime_types`) | X6 was reported upstream 2026-07-17 — fix it in the next release regardless |
| C6 | Delete the B2B `TaxMode` stub — **keeping one tax-inclusive/exclusive decision point** (§8 guardrail) | 100 lines, only effect is a `throw` |
| C12 | `SetPanelLocale` + `config/shops.php` — verify X8 first, then wire or delete | 55 lines |
| C17 | `HasShipment`, `InteractsWithOrders`, `HasAddresses` pair, `HasAvailability` duplicate | `HasCustomer` is implemented by IOW's `User` — leave that one |
| C24 | Four cart find/resolve classes → two | consumers call `ResolveCurrentCart`; touch when Cart HTTP is next open |
| C26 | `ResolveShippingZoneForCountry`, `OrderNumberGenerator` interface, `StockCast`, third report DTO, exception bases | each ~20–100 lines; `StockCast` also restores atomic `increment()` under the existing lock |
| W4 | `MoneyFormatter` → `Number::currency()` | 50 lines |
| S9–S15 | `carts.expires_at` index; `GET /api/cart` mints rows; whole-table reconcile; O(n²) prune hint; N-clause CASE; per-request schema introspection; two redundant indexes | none bite at 10× |
| D15, D18 | `MoneyFields` to `Currency/Filament`; `default_currency` out from under `cart.api` | tier hygiene |
| D5, D6 | `class_exists(ClientRepository)` fail-fast; `filament/filament` to `suggest` | hygiene |
| 4.5 | Deep-merge `mergeConfigFrom` | **careful**: deep-merging list-shaped keys (zones, sources) changes semantics; document `Config::set` instead |

### Expensive and ineffective — don't, or only when the file is open for another reason

| # | Item | Why not |
|---|---|---|
| C15 retrofit | Remove all ~30 `models.*` keys, `{Domain}::model()` facades, factory `modelName()` overrides, and re-type every relation | touches every domain and both consumers' config files for zero observable change. **Stop adding new ones** (cheap quadrant); strip a domain's registry when that domain is open anyway |
| C16 retrofit | Convert ~40 log-only events to direct logging | same: every subscriber in every domain, periphery docs in three repos, for no behaviour change. Rule change now; convert opportunistically |
| C25 | `AddToCart` FlowChain → two events | both consumers' published chains rewritten; the mechanism works and is used. Revisit only if a second chain never appears in a year |
| C21 | Move Storefront Actions/Resources under Agent | purely structural; fix the fake-`Request` plumbing (cheap) when a tool is next edited |
| W5, W6 | `intervention/image`; `staudenmeir/laravel-adjacency-list` | each adds a dependency to remove ~150–300 lines; GD and the PHP tree walk are defensible at this size. W6 only after C1, if the walkers still feel heavy |
| C23 reship loop, exception hierarchies, `PaymentGatewayManager` vs `Manager` | leave | reviewers checked; each is smaller than its replacement |

**Net if the top two quadrants land:** ~5,000 lines, 9 tables out of at least one consumer, both correctness issues, all four query-shape problems, and three parallel mechanisms (counter cache, chain-by-file-probe, log routing) gone. The bottom-left quadrant is another ~700 lines that arrive for free over the next few releases; the bottom-right is where the reviewers' line counts stop being a reason.
