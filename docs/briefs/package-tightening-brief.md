# Brief — Package tightening (DRY / cleanup / boilerplate consolidation)

> **Status: RELEASED ✅ (2026-06-11) as v0.36.0** — all three phases implemented + tested
> (suite 853 green, was 809 at baseline; +44 new: BootConfigMergeTest, MoneyFieldsTest,
> PackagePageBasesTest, EnabledCurrenciesConfigTest), merged to main, tagged, and both
> consumers bumped to `^0.36.0` the same day: in-other-worlds (785 tests green, CI + Deploy
> green @ cd7e8c2) and bianka (39 tests green on the 0.34→0.36 two-minor jump, Deploy green
> @ 7d8159c); both live sites respond post-deploy. Commits: 41784f8 (Phase 1), 93514c0
> (Phase 2), 392a20a (Phase 3); in-other-worlds 0f8e023 (WI-10 consumer side).
>
> **Key build refinements vs. the v3 plan below:** (1) **WI-10**: in-other-worlds `Product`
> turned out to define its own digital-aware `requiresShipping()` (`! is_digital`) that had
> been shadowing the package trait all along — same for the package's own test stub — so the
> trait was shadowed dead weight at every use site and the consumer fix is import-removal
> only, no new method; (2) **WI-2**: `list_orders` previously let `per_page <= 0` reach
> `paginate()`; the shared clamp floors it at 1 (deliberate micro-hardening, noted in the
> Phase 1 commit); (3) **WI-6**: the commands hook is `domainCommands()` (the framework's
> `ServiceProvider::commands()` already owns the obvious name), and Logging adopted the base
> with a `configKey()` override while keeping its historical hand-rolled `logging-config`
> publish tag; (4) **WI-7**: no Create-page base was needed (stubs were already minimal) and
> `PackagePageBasesTest` pins the 20-page List/Edit census; (5) **WI-4** additionally applied
> the same single-query derivation to the order table's refund badge (2 queries per row → 1).
>
> **Status: v3 (2026-06-11) — discuss pass 1 + adversarial critique pass 1 incorporated;
> build-ready pending sign-off.** All five decisions settled (§5); D5 scoped down to one trait
> (§10). Critique pass (4 parallel adversarial reviewers, claims re-verified against code)
> redesigned WI-4 (memoization dropped for a page-local fix), corrected WI-6's fit estimate
> (~half the claimed savings), added the null-policy design to WI-5, excluded PaymentStatus
> from WI-9, and hardened §6/§7 — full record in §11.
> Origin: full-package quality audit
> (6 parallel auditors over all 16 domains + cross-cutting skeleton; load-bearing claims verified
> by direct grep). Scope is **quality only** — duplication, dead config, boilerplate, consistency.
> Bugs and correctness pathology live in the silent-correctness audit (projects-root) and are
> explicitly out of scope here. Target release: **v0.36.0**, single release window, then bump both
> consumers.

---

## 1. What we're fixing

The package is structurally healthy: no meaningful dead code, clean migrations/factories/DTOs,
test scaffolding is good, domain symmetry is holding. Four clusters of accumulated drift remain:

1. **Money/percent formatting duplicated across Filament** — the cents↔decimal pattern is
   hand-rolled in 5+ places with inconsistent output (`21.00%` vs `21%`).
2. **Currency config split-brain** — `Currency::enabled()` reads `config('currency.enabled')`
   but no `currency.php` config exists anywhere; meanwhile `pricing.currencies` ships in config
   and is read by nothing. Both keys are silently inert.
3. **Byte-identical helpers in Agent tools** — `resolveStockableClass()` triplicated verbatim
   (~30 lines × 3); pagination clamping reimplemented with different constants.
4. **~1,000 lines of mechanical skeleton** — 17 ServiceProviders, 39 Filament page stubs, and
   8 LogSubscribers each repeat the same shape with only names varying. Base classes can absorb
   the mechanics *and enforce* the symmetry that copy-paste currently only imitates.

## 2. Periphery map

What this refactor touches at runtime and on the external surface:

- **Boot path (all 17 ServiceProviders):** merge-config, load-migrations, `Relation::morphMap`,
  `Event::subscribe(<Domain>LogSubscriber)`, `commands([...])`, `publishes([...])`, and three
  `$this->app->booted()` schedule hooks (Inventory, Pricing, Logging — each gated on its own
  `<domain>.schedule.enabled` key). WI-6 rewires all of this through a base class — boot **order
  and effects must be provably identical** (see §7).
- **Event subscribers (8):** structure changes (base class), content (event→LogEntry mappings,
  channels, context keys) does not. Audit-log output must be byte-identical.
- **Filament Schemas are consumer-embedded** (periphery.md §Filament Schemas): `PricingSchema`,
  `InventorySchema`, `TaxonomySchema`, `MediaSchema`, `TranslationSchema`, `VariantsSchema`
  static factories are called from in-other-worlds and bianka resources. **Every existing public
  signature stays.** The new shared money/percent helpers are additive; existing schema factories
  delegate to them internally.
- **Agent MCP tools are a live surface** (agent.inotherworlds.net, claude.ai connector).
  WI-2 extractions are internal-only; **response envelopes do not change in this brief** (the
  envelope inconsistency is parked as D4 — changing error shapes is consumer-visible).
- **Filament pages and resources:** in-other-worlds **extends the package's
  `OrderResource`** (`app/Filament/Resources/OrderResource.php` → `extends
  PackageOrderResource`) with its own `getPages()` override routing to consumer-local page
  classes that extend Filament's bases directly. *(Corrected in critique pass — v1/v2 claimed
  consumers only embed Schemas.)* Two consequences: package-internal page base classes (WI-7)
  are invisible to the consumer (safe), and any change to the package `OrderResource` itself —
  the WI-5 currency-select hydration swap included — **propagates into the consumer's admin
  panel through inheritance**; the consumer smoke-check must cover the IOW order screens
  specifically. Bianka extends no package Filament classes (verified by grep).
- **Config publish surface:** new `currency.php` config file (merged + publishable). Neither
  consumer has published `pricing.php` or references `pricing.currencies` / `currency.enabled`
  (verified by grep over both apps' `config/` + `app/`) — so the key deletion and the new file
  are both safe, no consumer migration needed.
- **periphery.md updates owed at build time:** new `Support\` namespace + what it exports; new
  `currency.enabled` config key; Schema-internal delegation (no external change). Per
  keep-docs-current: same commit as the change, not batched.

## 3. Invariants

- **No behavior change.** This is a pure refactor release: same queries, same events, same log
  entries, same rendered Filament output, same MCP responses. Full suite green at every phase.
- **No consumer-called signature changes.** Everything in periphery.md §External surface keeps
  its name, signature, and return type. New surface is additive only.
- **Dead-code removals require grep evidence across package + both consumers**, recorded in the
  commit message. (Job-contract discipline: nothing gets deleted to make a work item "done";
  the only deletions in scope are the two listed in WI-1 and WI-7, both already evidenced.)
- **Base classes must not become a third place to look.** A domain's provider/subscriber must
  remain readable on its own: hooks declare *what* (aliases, subscriber class, commands), the
  base owns *how*. No conditional spaghetti in the base.
- **Formatting parity proven, not assumed.** WI-5 lands with characterization tests asserting
  old output == new output for every replaced call site (EUR/USD/GBP, zero, null, large values).

## 4. Work items

### Phase 1 — surgical (all S)

- **WI-1: Currency config split-brain.** Create `src/Currency/config/currency.php` with
  `enabled` (null = all cases), merge in `CurrencyServiceProvider::register()`, add to
  publishes. Delete the dead `currencies` key from `src/Pricing/config/pricing.php`. Add a
  feature test proving `currency.enabled` actually filters `Currency::enabled()` through the
  real config merge (today the key can't load at all, so the README documents fiction).
  Ordering note (critique-checked): `StorefrontServiceProvider::register()` references
  `Currency::enabled()` but inside a **container binding closure** — it executes lazily at
  `StorefrontContext` resolution, never during register, so there is no provider-order hazard.
  Deploy note: consumers run `config:cache` in their images — the new key lands on the normal
  bump + redeploy, no extra step.
- **WI-2: Agent support extractions.** `Agent/Support/ResolveStockableModel` (single
  implementation of the triplicated `resolveStockableClass()` in `GetStockLevel`, `AdjustStock`,
  `ListStockLevels` — verified byte-identical) and `Agent/Support/PaginationParams` — a
  **parameterized** clamp (`fromArguments($arguments, int $max, int $default)`) used by
  `ListStockLevels` (200/50) and `ListOrders` (100/25); each tool passes its existing
  constants, so observable limits don't move. Scope note: `BrowseCatalog` paginates via the
  Storefront `ListBrowsables` action — that's a different layer, deliberately not touched.
- **WI-3: Voucher validation extract.** `CalculateVoucherDiscount` and `ApplyVoucher` repeat
  the same three checks (validity window, minimum order, currency match for fixed vouchers)
  with the same exceptions. Extract one shared guard (suggested: `Voucher::validateForUse()`),
  both actions call it; `ApplyVoucher` keeps its row lock around it.
- **WI-4: `refundedTotal()` repeated queries — page-local fix, NOT model memoization.**
  *(Redesigned in critique pass.)* Model-level memoization is a stale-read footgun here:
  Filament/Livewire keeps the `Order` instance alive across the request, and EditOrder's own
  refund actions create Refund rows mid-request without `refresh()` — a memoized
  `isRefunded()` would then report the pre-refund state on the very screen that just issued
  the refund. The model stays untouched (its repeated `sum()` is correct and, at our scale,
  cheap). The only fix: `EditOrder::afterFill()` computes `$record->refundedTotal()` once into
  a local and derives both notification conditions from it. Smaller than drafted, zero risk.

### Phase 2 — structural (M)

- **WI-5: Shared Filament money/percent helpers** — the highest-leverage DRY fix. One class
  (proposed `Support/Filament/MoneyFields`) exporting `moneyInput()` (cents↔decimal form field),
  `moneyColumn()`, `percentInput()`, `percentColumn()` (basis points↔display). Replaces the
  hand-rolled copies in `PurchasingSchema` (×2), `PurchaseOrderResource::costField`,
  `PricingSchema::moneyField` (private — delegates, signature kept), `VoucherResource`,
  `TaxRateResource`, and the duplicated Currency-enum select hydration in `CommerceSchema` /
  `OrderResource`. Display normalization (`21.00%` vs `21%`) is decision D2.
  **Null-dehydration policy (critique-surfaced, design requirement):** the call sites split
  two ways, and the split tracks column nullability, so it's *correct*, not drift —
  null→`0` for non-nullable columns (`CommerceSchema` unit_price + line_total,
  `PurchasingSchema` unit_cost, `OrderResource` scalar money fields) vs null→`null` for
  nullable ones (`PricingSchema` amount/compare_at, `PurchasingSchema` input_vat).
  `moneyInput()` therefore takes an explicit `nullable: bool = false` named argument and each
  call site keeps its current policy exactly; the characterization tests assert null and
  empty-string dehydration per site. Documented limitation: helpers hardcode 2 decimals (all
  enabled currencies are 2-decimal; `Currency::decimals()` exists if JPY-style currencies ever
  land — the helper grows a currency param then, not now).
- **WI-6: `Support/DomainServiceProvider` base** — *(scope halved in critique pass; the
  provider-by-provider read showed far more diversity than the audit claimed).* Hooks:
  `domainDir(): string` (each child returns `__DIR__` — required, a base-class `__DIR__`
  resolves to `src/Support/`), `configKey(): string` (defaults from the domain dir name;
  **Logging overrides to `domain-log`**), `morphAliases(): array`, `logSubscriber(): ?string`
  (explicit, never derived — **Shipping's is `ShipmentLogSubscriber`**, name reflection would
  lie), `commands(): array`. Base implements the dominant order (merge → migrations →
  morphMap → subscribe → commands → publishes); `boot()` stays overridable with
  `parent::boot()` for providers with extra behavior. **Honest fit table:** full fit — 2
  (Location, Purchasing); fit minus unused hooks — 5 (Media, Shipping, Tax, Translation,
  Variants); fit + `boot()` override for extras — 5 (Agent deferred routes, Commerce cart
  routes + FlowChain registration, Inventory Livewire binding + schedule, Pricing schedule,
  Taxonomy observers + `MaintainCategoryCounts` — which is an event subscriber but *not* a log
  subscriber; it stays an explicit boot line); **skipped** — 5 (Currency and FlowChain are
  smaller than the base would make them, Storefront and Payment are register-centric, Stripe
  is wholly conditional). Realistic savings ~120–150 lines, not 260. The case for keeping it
  at half the savings: the base *enforces* the canonical sequence and makes a new domain's
  provider correct by construction — that was always the larger half of the value.
- **WI-7: Filament page base classes** — *(counts corrected in critique pass: 30 page
  classes, not 39; ~15 are true stubs).* `Support/Filament/{PackageListRecords,
  PackageEditRecord}` carrying the standard header actions (Create on list, Delete on edit).
  No Create base — the Create stubs are already 2-liners with nothing to absorb. The ~15 true
  stubs shrink to `$resource` + parent; the ~11 customized pages (translation-sync
  Category/Tag/Option pages, `CreateCustomer`/`CreatePurchaseOrder` record handlers, and
  `EditOrder` with its 191 lines of refund actions) keep their hooks and merely swap their
  parent class — the base must therefore make `getHeaderActions()` overridable, not final.
  Also delete the three empty `getRelations(): []` overrides (`CategoryResource`,
  `TagResource`, `TaxRateResource`) — Filament v5's `Resource::getRelations()` already returns
  `[]` (verified in vendor); `OrderResource`/`CustomerResource` keep theirs (real relation
  managers). *The audit's "17 empty overrides / ~789 lines" claims were wrong; all counts here
  are re-verified.*
- **WI-8: LogSubscriber base.** `Support/LogSubscriberBase` (or `Logging/`, see D1) with the
  constructor and a `protected log(LogLevel $level, string $message, array $context,
  ?LogActor $actor = null)` helper — the actor param is required by the design *(critique
  catch)*: `CommerceLogSubscriber`'s RefundRecorded handler passes an explicit `LogActor`, and
  levels genuinely vary per handler (Error for PaymentFailed/ShipmentLost, Warning for
  OrderConfirmationBlocked/ReturnedToSender). Each subscriber keeps its `CHANNEL`,
  `subscribe()` map, and handler bodies. ~5 lines × ~40 handlers. Channel stays per-subscriber
  data, not base magic (Pricing deliberately logs to `commerce` — that nuance must stay
  visible in the subscriber).

### Phase 3 — polish (S)

- **WI-9: `Transitionable` enum interface + `StateTransitions` trait** for `OrderStatus`,
  `ShipmentStatus`, `PurchaseOrderStatus` (`label()/color()/allowedTransitions()` + shared
  `canTransitionTo()`). **PaymentStatus is deliberately excluded** *(critique catch: it has
  only `label()`/`color()` — verified)*: its transitions are driven by gateway webhook mapping,
  not a local state machine, and inventing `allowedTransitions()` for it would be new behavior
  in a refactor release. Whether PaymentStatus *should* get a local transition guard is a
  design question for another day, noted in the interface docblock. Near-zero line savings;
  value is the documented contract for future status enums. **In per D3.**
- **WI-10: Remove `InteractsWithShippability` (D5, scoped down — see §10).** The trait's
  entire body is `requiresShipping(): bool { return true; }` — a constant masquerading as
  behavior; the `HasShippability` contract stays. Usage sites (verified by grep):
  in-other-worlds `app/Models/Product.php` and the package's `tests/Stubs/
  TestShippableCartable.php`; bianka does not use it. Each implements the one-line method
  directly. The other four flagged traits (`InteractsWithShipment`, `InteractsWithCustomers`,
  `InteractsWithAddresses`, `InteractsWithOrders`) are **kept**: each ships a default relation
  that resolves its model through the registry (`Commerce::customer()` etc.) — exactly what the
  CLAUDE.md trait rule exists to support, and hand-copied relations in consumers would silently
  break config-swapped models. Update Shipping README + periphery.md §Capability contracts in
  the same commit.

## 5. Decisions (all resolved — discuss pass 1, 2026-06-11)

- **D1 — RESOLVED: shared `Support\` namespace is in.** `src/Support/` →
  `InOtherShops\Support\` (new PSR-4 entry), hard rule: *domain-agnostic mechanics only*
  (provider plumbing, Filament field factories, enum traits) — never domain logic, never
  models, never a dumping ground. Known tension, accepted: a future package split takes
  `Support` along or publishes it as its own tiny package.
- **D2 — RESOLVED: percent display normalizes to trailing-zeros-stripped** (`21%`, `7.5%`)
  everywhere. The one deliberate visible change in this release; changelog notes it, and the
  WI-5 characterization tests assert the chosen format for the tax-rate column (the previously
  divergent site).
- **D3 — RESOLVED: WI-9 is in.**
- **D4 — RESOLVED: parked to the cross-project task list** (`~/iow/Tasks/TASKS.md`
  §Programming). Standardizing the MCP error envelopes is consumer-visible on a live surface
  and gets its own Agent-domain release. Not in v0.36.0.
- **D5 — RESOLVED: in scope, but scoped down to one trait (WI-10).** Pre-launch, both
  consumers local, so the breaking change is fine — but the evidence check showed only
  `InteractsWithShippability` actually violates the CLAUDE.md trait rule (constant return, no
  behavior). The four relation-carrying traits stay: they exist precisely to keep
  registry-resolved relations out of consumer copy-paste. Full reasoning + usage grep in §10.

## 6. Phasing

Each phase lands as its own commit set, suite green before the next starts. All three phases
ship together as **v0.36.0** (one release window per package-breaking-changes policy, though
nothing here should even be breaking), then both consumers bump + smoke-check admin panels.

1. Phase 1 (WI-1..4) — independent, no ordering constraints.
2. Phase 2 (WI-5..8) — `Support\` namespace (D1, resolved) lands once, with the first WI that
   needs it.
3. Phase 3 (WI-9, WI-10).
4. periphery.md + domain READMEs updated in the same commits as the changes they describe.
5. Consumer bumps are **manual constraint edits, not `composer update`** *(critique catch)*:
   both consumers pin with caret on 0.x (`^0.35.0` IOW, `^0.34.0` bianka), and composer treats
   0.x minors as breaking — `^0.35.0` excludes 0.36.0. Each consumer edits its composer.json
   to `^0.36.0`, then updates. in-other-worlds additionally adds `requiresShipping()` to
   `app/Models/Product.php` (WI-10) in the same change-window — the trait removal and the
   consumer edit must land together. Bianka is constraint-edit-only.
6. The package repo has **no CI** (no `.github/` — verified): the full suite runs locally
   before tagging, and the §7 plan is the only gate. Consumer CI (which does exist) catches
   integration fallout only after the bump.

## 7. Test plan (the false-greens to kill)

- **Boot parity (WI-6) — concrete, lands BEFORE the provider refactor:** a new
  `BootConfigMergeTest` in the package suite asserting one package-default config key per
  domain (e.g. `config('inventory.schedule.enabled') === true`) on a plain testbench boot.
  This works because `tests/TestCase::defineEnvironment()` sets only DB config + morph aliases
  (verified) — so a present default *proves* the provider's `mergeConfigFrom` ran. Plus,
  post-swap: per domain, morph aliases resolve, the log subscriber receives a fired event,
  registered commands exist in Artisan, the three schedules register exactly once. Writing the
  test first turns the base-class swap into a guarded change instead of an act of faith.
- **Formatting characterization (WI-5) — snapshot tests land BEFORE the refactor** *(critique
  catch: nothing currently asserts any of these formats, so a silent change would pass the
  suite)*: money 0 / 1 / 999 / 100000 / null / `''`; percent 2100 / 750 / 0 bps; null and
  empty-string **dehydration** per call site (the null→0 vs null→null policies of WI-5 must
  each be pinned). The TaxRateResource percent column gets an explicit assertion of the D2
  format (`21%`) since it's the one surface that visibly changes; VoucherResource already
  strips zeros today.
- **Currency config (WI-1):** `currency.enabled` set in app config filters `enabled()`;
  unset → all cases; `pricing.currencies` no longer exists in the published config.
- **Header actions (WI-7):** one Filament page test per base class asserting Create/Delete
  header actions still render — the stub deletion's only observable risk.
- **Consumer-side `HasShippability` (WI-10):** an in-other-worlds test asserting
  `Product implements HasShippability` *and* `requiresShipping() === true` — without it, a
  dropped implementation is invisible because `Cart::requiresShipping()` falls back to `true`
  for non-implementors (Cart.php:57), which is exactly the right value to mask the bug.
- **No memoization test (WI-4 redesign):** the page-local fix needs no model test; the
  EditOrder behavior is covered by the existing refund-action tests.

## 8. Out of scope (and why)

- **Reconciliation loop→SQL rewrites** (`ReconcileStock`, `ReconcilePurchaseReceipts`) —
  contradicts the scale stance; the loops are more readable than the subquery would be.
- **`OrderNumberGenerator` interface collapse** — flagged by the audit as YAGNI, rejected: a
  container-bound interface is exactly how a consumer swaps numbering schemes. Extension
  surface, not over-abstraction.
- **Tag/Category asymmetry** (TagAttached/Detached events with no listener, bulk-detach not
  dispatching, missing transaction vs. AttachCategory) — this is a correctness/design question,
  routed to the silent-correctness fix phase, not a DRY pass.
- **Registry classes** (`Inventory::stockItem()` etc.) — intentional config-driven model
  swapping; keep.
- **MCP error envelope normalization** (D4) — parked to TASKS.md; own Agent-domain release.
- **The four relation-carrying `Interacts*` traits** — kept deliberately (see D5 / §10); only
  `InteractsWithShippability` goes (WI-10).
- **`elapsedMs()` duplication** (AgentTool, FlowChain) — two sites, trivial; extract only if a
  third appears.

## 9. Audit corrections + provenance

Findings came from 6 parallel auditors (5 domain-group sweeps + 1 cross-cutting). Claims that
did **not** survive direct verification, recorded so they don't resurface:

- `CountryNotShippableException::forCountry()` is **not dead** — Shipping README documents it
  as the consumer-thrown typed failure for null resolver results. Intentional API.
- `Order.shipping_cost_currency` is **not unused** — in-other-worlds `ShowOrder` agent tool
  reads it (`app/Project/AgentTools/ShowOrder.php:128`).
- "17 empty `getRelations()` overrides" — actually 3 (the other 2 overrides carry real
  relation managers; the remaining 12 resources don't override it at all).

## 10. Discuss changelog

**Pass 1 (2026-06-11):** D1–D3 accepted as drafted. D4 moved to the cross-project task list.
D5 approved in principle (pre-launch, both consumers local), then **scoped down on evidence**:
the audit had lumped five traits together, but reading them shows four carry a default
registry-resolved relation —

- `InteractsWithShipment` → `shipments()` morphMany via `Shipping::shipment()`
- `InteractsWithCustomers` → `customer()` morphOne via `Commerce::customer()`
- `InteractsWithAddresses` → `addresses()` morphMany via `Location::address()`
- `InteractsWithOrders` → `orderLines()` morphMany via `Commerce::orderLine()`

— which is the legitimate trait shape per CLAUDE.md ("ship a trait when it carries default
relations, scopes, or behavior"). Removing them would push registry resolution into consumer
copy-paste and silently break config-swapped models. Only `InteractsWithShippability`
(constant `true`, no relation) violates the rule → WI-10. Usage grep at decision time:
in-other-worlds `Product.php` + package stub `TestShippableCartable.php`; bianka unaffected.
The contract itself is load-bearing — `Cart::requiresShipping()` type-checks cartables with
`instanceof HasShippability` (Cart.php:57) — so only the trait goes, never the contract.

## 11. Critique changelog

**Adversarial pass 1 (2026-06-11):** four parallel reviewers (provider base / Filament /
Phase-1 surgical / release+test mechanics), every blocker-level claim re-verified against code
before acceptance.

**Accepted — plan changed:**
- **WI-4 redesigned.** Model memoization would stale-read on the EditOrder screen itself
  (Livewire keeps the instance; the page's own refund actions insert Refund rows mid-request
  with no `refresh()`). Replaced with a page-local computation; model untouched.
- **WI-6 scope halved.** Provider-by-provider read: 2 full fits, 5 minus-unused-hooks, 5
  boot-override, 5 skipped (Currency, FlowChain, Storefront, Payment, Stripe). Non-derivable
  names made explicit hooks: Logging's `domain-log` config key, Shipping's
  `ShipmentLogSubscriber`, per-child `domainDir()` (base-class `__DIR__` resolves to
  src/Support/). Taxonomy's `MaintainCategoryCounts` is a non-log event subscriber — explicit
  boot line, not a base hook. Savings re-estimated 120–150 lines; kept for the
  symmetry-enforcement value.
- **WI-5 gained its null-policy design.** Call sites split null→0 (non-nullable columns) vs
  null→null (nullable: Pricing amount/compare_at, Purchasing input_vat) — correct per schema,
  so the helper takes `nullable: bool` and every site keeps its exact dehydration.
  2-decimal hardcode documented as a limitation.
- **WI-9: PaymentStatus excluded** — it has no transition methods (verified); its state is
  gateway-driven. Interface renamed `Stateful` → `Transitionable`, scoped to
  Order/Shipment/PurchaseOrder.
- **WI-8 helper signature** gained `?LogActor $actor = null` (RefundRecorded passes one).
- **§2 periphery corrected:** in-other-worlds *extends* the package `OrderResource`; package
  Resource changes propagate into its panel. v1/v2's "consumers only embed Schemas" was wrong.
- **§6 hardened:** caret-on-0.x means manual composer constraint edits in both consumers; the
  package has no CI, so the local suite is the only pre-tag gate.
- **§7 hardened:** characterization/snapshot tests and `BootConfigMergeTest` land *before*
  their refactors (nothing currently asserts any display format — a silent format change would
  pass the suite). WI-7 counts corrected (30 pages; ~15 true stubs; no Create base needed).

**Rejected — critique wrong or moot:**
- "WI-1 provider-ordering blocker": `StorefrontServiceProvider` references
  `Currency::enabled()` inside a container **binding closure** — lazy, runs at resolution, no
  register-order hazard.
- "WI-7 inheritance inverted / benefit infeasible": the brief never claimed consumer stubs
  would shrink; WI-7 was always package-internal. The real catch from that thread was the §2
  periphery correction above.
- "Architecture tests could block `Support\` imports": no deptrac/phpat/CI exists in the repo.
- "Pagination extraction contradicts itself": the brief already specified per-tool constants
  via parameterization; clarified wording in WI-2 rather than changing the plan.
