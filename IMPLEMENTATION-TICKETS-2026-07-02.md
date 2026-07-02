# in-other-shops — Implementation Tickets (from AUDIT-2026-07-02)

Handoff spec for an implementer (likely Opus) working **without this conversation's context**. Source findings live in `AUDIT-2026-07-02.md`; this doc turns them into executable, sequenced tickets with verification steps and decision gates.

## How to use this doc — read before starting

1. **Read first, for context you don't have:** `CLAUDE.md` (esp. §"A Modular Monolith With One Real Seam" — the direction was reframed 2026-07-02; do **not** do package-split prep), `docs/writing-tests.md` (the package's test-trust rules), and `docs/periphery.md` (external surface — changing a `Has*` contract or public action signature is a cross-consumer event).
2. **Trust symbols, not line numbers.** Every `file:line` here is a decaying snapshot produced by audit agents and not independently re-verified except where a ticket says "✔ verified." Before editing, `grep` the named symbol — earlier tickets shift later tickets' lines in the same file.
3. **Tag legend:**
   - `MECHANICAL` — unambiguous; execute as written.
   - `BUG-FIRST` — a correctness hazard. Write a **failing test that pins the current wrong behavior first**, then fix, then the test goes green. A refactor-only pass can silently preserve the bug.
   - `DECISION` — blocked on Jelte. Do not guess a default; see Wave 0.
   - `DEFERRED` — audit says "don't do until a 3rd instance appears" or "only when X use case arrives." Listed for completeness; **do not implement now.**
4. **Verification runner:** `composer test` (Orchestra Testbench / PHPUnit). New tests go under `tests/` mirroring the domain. A ticket isn't done until its stated check passes.
5. **Overlap warning:** the Agent-domain findings appear in the audit as both Section 4 (AG-*) and Section 5 (DL-M1/M2). They are the **same work** — this doc collapses them into tickets T-A1/T-A2. Don't double-count.
6. **Release discipline:** package is consumed from Packagist, not symlinked. Breaking changes to `Has*` contracts / public action signatures are fine in a single release window (both consumers pre-launch) but must land in both consumers the same window — see the periphery doc. Tickets that touch external surface are marked ⚠EXT.

---

## Wave 0 — Decisions (RESOLVED by Jelte 2026-07-02)

All five are decided; concrete instructions are baked into the tickets below. **No production data exists yet — no orders, no shipments** — so migration changes need no reseed/backfill coordination and label changes break nothing.

- **D1 (→ T-E1, enum `label()` casing): sentence-case is correct.** Verified against the code: the multi-word labels are `Partially received`, `In transit`, `Returned to sender` (sentence-case) vs the lone outlier `Partially Refunded` (Title Case) on `PaymentStatus`. The shared default `ucfirst(str_replace('_',' ',$value))` produces sentence-case naturally. `PaymentStatus::PartiallyRefunded` becomes `Partially refunded` (the correction). Only `AddressType::ShippingAndBilling => 'Shipping & Billing'` keeps an override (ampersand can't be derived from the value).
- **D2 (→ T-A6): wire `displayName()` to `title()`.** ✅
- **D3 (→ T-S2): one group per domain** (drop the `'Shop'` catch-all). ✅
- **D4 (→ T-B4): do NOT cascade.** `restrictOnDelete` on `shipment_items.order_line_id`. ✅
- **D5 (→ T-SEC1): proceed** with the `admin_client_ids` allowlist + doc guard. ✅ (Reachability check in the consumer is still worth doing, but no data blocks it.)

---

## Wave 1 — Security (do first; highest severity)

### T-SEC1 — Harden agent admin elevation `[RESOLVED D5: proceed]` `[HIGH]` ⚠EXT
- **Source:** SEC H-A (confidence *Med* — contingent on consumer Passport config).
- **Files:** `src/Agent/Http/Middleware/AuthenticateAgent.php` (grep `is_admin`, `agent.admin`), `src/Agent/config/agent.php`.
- **Before coding:** verify the exploit path against the *actual consumer* (in-other-worlds) — is `agent.admin` registered as a grantable Passport scope there? If not, the exploit is latent, not live; still worth hardening, but confirm severity. (No data blocks this — it's an auth-config question, not a data question.)
- **Do:** add an `agent.auth.oauth.admin_client_ids` allowlist to config; in addition to the scope check, require the token's client be confidential + on the allowlist (`client->firstParty()` or `admin_client_ids`). Fail closed. Document in `agent.php` that `agent.admin` must never be an interactively-grantable Passport scope.
- **Verify:** add a middleware test — base-scope token from a public client requesting `agent.admin` must **not** get `is_admin`; an allowlisted confidential client must. `composer test`.

### T-SEC2 — Ship default-deny policy base for package Filament resources `[HIGH]` ⚠EXT
- **Source:** SEC M-A + DES H2 (converged). This is the headline finding.
- **Files:** all `src/*/Filament/Resources/*Resource.php` (grep `class .*Resource extends`); new base in `src/Support/Filament/`.
- **Do:** ship a policy base (or a `canAccess()` default-deny) so a package Resource is locked unless a capability/policy grants it — the safe default becomes "no access," not "full access." Enumerate every package model needing a policy mapping and add it to `docs/periphery.md`. Consider documenting/enabling Filament strict-authorization mode (missing policy → thrown `LogicException` instead of silent allow).
- **Verify:** a panel user with no granting policy is denied a package Resource (test or manual against in-other-worlds' `can_manage_*` booleans). Confirm in-other-worlds' existing 9 policies still pass.
- **Note:** pairs with the Filament Plugin work (T-S-PLUGIN, deferred to "before consumer 3") but the default-deny is worth landing now independently.

### T-SEC3 — Gate or coarsen agent stock-level reads `[DECISION-lite]` `[MED]`
- **Source:** SEC M-B.
- **Files:** `src/Agent/Tools/GetStockLevel.php`, `src/Agent/Tools/ListStockLevels.php` (grep `isAdmin`).
- **Decide + do:** either gate both behind `isAdmin()` (like `AdjustStock`), or return `in_stock` boolean for non-admins and full `stock_level` for admins. **Recommended:** the latter — customers get a signal, exact quantities stay admin-only.
- **Verify:** tool test — base-scope caller sees no exact `stock_level`; admin caller does.

### T-SEC4 — Fail-closed canonical URL `[LOW]`
- **Source:** SEC L-A.
- **Files:** `src/Agent/Support/CanonicalUrl.php`, Agent service provider boot.
- **Do:** when `agent.auth.oauth.enabled` is true and `agent.canonical_url` is blank, throw at boot instead of deriving host from the request.
- **Verify:** boot test — OAuth-enabled + blank canonical_url throws.

---

## Wave 2 — Correctness hazards (BUG-FIRST; test the current wrong behavior first)

### T-B1 — Collapse the VAT breakdown shape onto its DTO `[BUG-FIRST]` `[HIGH]` ✔ verified dup
- **Source:** DL-M4. `taxSummary()` is byte-identical in `src/Commerce/Order/Models/Order.php` (~:68) and `src/Commerce/Order/Models/Refund.php` (~:60); the write direction is duplicated in `CreateOrder.php` (~:210) and `RecordRefund.php` (~:103). Four sites encode one persisted VAT shape → drift = silent accounting bug.
- **Do:** put both directions on `src/Pricing/DTOs/TaxBreakdownLine.php` — `listFromRows(?array): array` (read) and `serializeMany(list): array` (write). Repoint all four sites. Commerce already depends on Pricing DTOs — no new edge. **Also check** `ReverseTax.php` and `CalculateTotal.php` (they touch `TaxBreakdownLine`) consume the new methods cleanly.
- **Verify (bug-first):** add a test asserting read(write(rows)) round-trips and that Order and Refund produce identical shapes for the same rows. Then refactor. `composer test`.

### T-B2 — Move cart currency + unit-price fallback onto the models `[BUG-FIRST]` `[MED]` ✔ verified sites
- **Source:** DL-M3. `Currency::from(config('commerce.cart.api.default_currency','EUR'))` is verbatim in `ResolveCurrentCart.php`, `CartItemResource.php`, `CartResource.php`, `FindOrCreateCartItemStep.php`; unit-price fallback ("snapshot → live cartable price → null") is duplicated between `CartItemResource` and `CartResource`. Hazard: a snapshot-rule change in one resource makes `line_total` disagree with `subtotal`.
- **Do:** `Cart::effectiveCurrency(): Currency` and `CartItem::effectiveUnitPrice(Currency): ?int` on the models (fits "domain invariants belong on the model"). Kill the three private `resolveCurrency()` helpers; `CartResource::subtotal()` collapses to a sum over `effectiveUnitPrice`.
- **Interacts with DES-M3:** the `default_currency` literal ultimately wants a `currency.default` home in the Currency domain (T-D3). Do T-B2 first (dedupe to the model), then T-D3 can repoint the single resolver.
- **Verify (bug-first):** test that `sum(line.line_total) == cart.subtotal` for a mixed cart, and that changing the snapshot rule in one place moves both. `composer test`.

### T-B3 — Dedupe Purchasing status writers + close the unguarded write `[MED]` ✔ verified
- **Source:** DL-M5. `ReceiveItems.php` gates on `isReceivable()` (coarse) then writes `$order->update(['status' => $target])` directly (~:111) **without** `canTransitionTo`; `PlacePurchaseOrder` and `CancelPurchaseOrder` both use the transition guard. (Note: the `match` at ~:107 constrains `$target` to PartiallyReceived/Received/current, so this is a latent inconsistency, not a live oversell — but it sidesteps the state machine.)
- **Do:** a Purchasing-local `UpdatePurchaseOrderStatus` action mirroring `Shipping/Actions/UpdateShipmentStatus`, used by all three writers. Routes the `ReceiveItems` write through `canTransitionTo`.
- **Verify:** test that an invalid target transition throws even when `isReceivable()` is true. `composer test`.

### T-B4 — Cross-domain FK cascade on shipment_items `[RESOLVED D4: do not cascade]` `[MED]`
- **Source:** MIG-3. `create_shipment_items_table.php` uses `constrained()->cascadeOnDelete()` on `order_line_id` — deleting an order line silently deletes shipment history.
- **Do:** change `cascadeOnDelete()` to `restrictOnDelete()` on `shipment_items.order_line_id`. No data exists yet (no orders/shipments), so edit the migration in place — no reseed/backfill needed.
- **Verify:** deleting an order line that has a shipment item is blocked (throws / FK constraint). Add a test. `composer test`.

---

## Wave 3 — Mechanical DRY (execute as written; delete dead code first)

### T-M0 — Delete dead code (do BEFORE T-M1 so the factory pass doesn't touch doomed methods) `[MECHANICAL]` ⚠EXT for the contract ones
- **Source:** DL-L4/L5/L7.
- **Do:** delete `InteractsWithTranslations::translationsFor()` (not on a contract — safe); `InteractsWithMedia::mediaInCollection()` + its `HasMedia` contract method; `InteractsWithPrices::priceCurrencies()` + its `HasPrices` contract method (these two are ⚠EXT — contract change, one release window, update `docs/periphery.md`). Drop unused `$quantity` param in `CreateOrder::resolveLineData` + its callsite. Remove the redundant `$collection ?? ''` re-coalesce in `StoreMedia`. Remove the unreachable `status !== Pending` recheck in `ConfirmReservation`. Delete the redundant bare `'categories'`/`'tags'` eager-load keys in `ResolvesEagerLoading` and the empty `src/Storefront/Http/` dir.
- **Verify:** grep confirms zero callers before each delete; `composer test`.

### T-M1 — `newFactory()` → `static $factory` across 32 models `[MECHANICAL]` ✔ verified count=32
- **Source:** DL-H1. Every model repeats `protected static function newFactory(): Factory { return new XFactory; }` + a `Factory` import (~128 lines).
- **Do:** replace each with `protected static string $factory = XFactory::class;` and drop the now-unused import. **Do NOT** build a convention-resolving trait — the Commerce subdomains (`Cart`/`Customer`/`Order` sharing `Commerce\Database\Factories`) break string-munging resolution.
- **Verify:** `composer test` (factories are exercised throughout the suite); a green suite is the proof.

### T-M2 — `RunsLockedTransactions` concern `[MECHANICAL]` `[HIGH-value]`
- **Source:** DL-H2. Lock–re-read–sync-back idiom (`lockForUpdate` + `setRawAttributes(..., true)`) hand-rolled in `ConfirmOrder`, `UpdateOrderStatus`, `RefundPayment` (+ `ExpireAbandonedOrders` without sync-back).
- **Do:** `src/Support/Concerns/RunsLockedTransactions.php` (Support is domain-neutral) exposing `withLocked(Model $model, Closure $fn): mixed` — locks, hands the locked instance to the closure, syncs attributes back on return. Guards stay per-action; only the mechanical frame moves.
- **Verify:** existing concurrency tests for those actions stay green; the `setRawAttributes` behavior is preserved. `composer test`.

### T-A1 — Extract `ResolveBrowsableModel` (Agent) `[MECHANICAL]` ✔ verified sites
- **Source:** AG-H1 / DL-M1. `resolveModelClass()` triplicated: `BrowseCatalog.php` (~:87), `ShowBrowsable.php` (~:80, byte-identical), `Support/ResolveStockableModel.php` (~:19, same + `HasStock` check; error text drifts).
- **Do:** `src/Agent/Support/ResolveBrowsableModel` (invokable → `class-string<HasStorefrontPresence>`); `ResolveStockableModel` composes it + adds the `HasStock` assertion; both tools drop their private copies. Re-unifies the error string.
- **Verify:** tool tests for unknown-type error message are identical across tools. `composer test`.

### T-A2 — `AgentTool::failure()` + `paginationMeta()` helpers `[MECHANICAL]`
- **Source:** AG-M1/M2 / DL-M2. Error envelope hand-built at 6 sites; pagination `meta` block at 3 package sites (+4 consumer tools).
- **Do:** add `protected function failure(string $code, string $message, ?array $target = null): array` and `protected function paginationMeta(LengthAwarePaginator $p): array` to `src/Agent/AgentTool.php`; repoint the 6 + 3 sites. (Optional `success()` twin — used by all 11 tools.) Stop there; don't add a declarative `$requiresAdmin` flag for only 2 admin-gate sites.
- **Verify:** tool output shapes unchanged. `composer test`.

### T-A3 — BrowseCatalog pagination floor `[MECHANICAL]` `[bug-adjacent]`
- **Source:** AG-M3. `BrowseCatalog` bypasses `PaginationParams`; `Storefront/Actions/ListBrowsables` caps at 100 but has no `max(1, …)` floor, so `per_page: 0`/negative reaches `paginate()`.
- **Do:** clamp via `PaginationParams` in BrowseCatalog (or add the floor in `ListBrowsables::paginate()` — prefer the latter, it fixes it for all callers).
- **Verify:** test `per_page: 0` and negative → clamped to ≥1. `composer test`.

### T-A4 — `RemoveFromCart` delegation `[MECHANICAL]`
- **Source:** DL-M7. `UpdateCartItemQuantity::removeItem` is byte-for-byte `RemoveFromCart`.
- **Do:** inject `RemoveFromCart` into `UpdateCartItemQuantity` and delegate.
- **Verify:** cart tests green. `composer test`.

### T-A5 — Map the shipping audit channel `[MECHANICAL]` `[flag]`
- **Source:** DL flag / DES L1. `ShipmentLogSubscriber` logs to channel `'shipping'` but `Logging/config/domain-log.php` doesn't map it → shipping audit lines silently route to the `daily` default.
- **Do:** add the `shipping` channel to the map (one line). While there, consider `pricing` too (also absent, but Pricing's exception is documented in-code — leave unless intended).
- **Verify:** a shipment audit event lands in the dedicated channel (extend an `AuditPipelineRowTest`-style check).

### T-A6 — `displayName()` resolution `[RESOLVED D2: wire to title()]` ⚠EXT
- **Source:** AG-M4 / DL-L6.
- **Do:** add `public function title(): ?string { return static::displayName(); }` to `AgentTool` so the name surfaces in MCP `tools/list`. Keep `displayName()` on the contract.
- **Verify:** `tools/list` output includes the title. `composer test`.

### T-A7 — Misc Agent/Pricing micro-DRY `[MECHANICAL]` `[LOW]`
- **Source:** DL-L6. `PriceData`→column mapping duplicated in `CreatePrice` vs `UpdatePrice` → `PriceData::attributes()`. `Agent/Agent.php` facade has zero callers → document in README or delete (Jelte's call — treat as DECISION-lite, recommend delete).

---

## Wave 4 — Scaffolding (higher effort; some interact with Wave 1)

### T-E1 — `HasLabel` enum trait `[RESOLVED D1: sentence-case]` ⚠EXT (admin labels)
- **Source:** DL-M6. 8 enums hand-roll `label()`; 6 are mechanical transforms of the value.
- **Do:** `src/Support/HasLabel.php` with default `ucfirst(str_replace('_',' ',$this->value))` (yields sentence-case — the correct/dominant convention). Apply to all 8 enums. **Only override** `AddressType::ShippingAndBilling => 'Shipping & Billing'` (ampersand). This changes `PaymentStatus::PartiallyRefunded` from `Partially Refunded` → `Partially refunded` — intended (it was the lone Title-Case outlier); no data/UI depends on it yet. Auto-satisfies `Transitionable::label()`.
- **Before applying per-enum:** confirm each enum's default output equals its current label (they should, except the two noted); if any value doesn't transform cleanly (an abbreviation), keep an override for that case.
- **Verify:** enum label tests reflect sentence-case; `PartiallyRefunded` asserts `Partially refunded`. `composer test`. **Flag in CLAUDE.md:** this establishes a Support-level label convention.

### T-S1 — `SavesTranslatableForm` Filament trait `[MECHANICAL]` `[MED]` `[closes a latent TipTap trap]`
- **Source:** FIL-1. The `mutateFormDataBeforeFill`→`mutateFormDataBeforeSave`(unset)→`afterSave`(saveFormData) dance is copy-pasted across 6 Create/Edit pages (Category, Tag, Option) and does **not** use the existing `SyncsManualFormState` trait.
- **Do:** `src/Support/Filament/SavesTranslatableForm` composing `SyncsManualFormState`, declarative `syncSchemas(): array`. Reduces each of the 6 pages to one method **and** closes the dehydrated-state/RichEditor gap the trait exists to prevent.
- **Verify:** create+edit of a translatable resource round-trips translations/media; add a case for the RichEditor dehydration path. `composer test` + manual in a consumer panel.

### T-S2 — `NavigationGroup` enum `[RESOLVED D3: one group per domain]`
- **Source:** FIL-2. Nav-group is a scattered string literal with an undocumented Shop-vs-domain split.
- **Do:** extract `src/Support/Filament/NavigationGroup` enum with **one group per domain** — drop the `'Shop'` catch-all; Pricing/Variants/Tax each get their own group like Commerce/Purchasing/Taxonomy already do. Repoint every resource's `navigationGroup` to the enum.
- **Verify:** panel renders each domain's resources under its own group; no `'Shop'` string remains (grep).

### T-B-MIG1 — `status` column macro + widen `orders.status` `[MECHANICAL]` `[HIGH-in-migrations]`
- **Source:** MIG-1. Five tables carry `status` at three lengths with inconsistent defaults/indexes; `orders.status` is `string(20)` and `partially_refunded` is 18 chars — one edit from overflow.
- **Do:** introduce a `Blueprint::macro('status', ...)` (register once in a `Support` provider, **not** the abstract `DomainServiceProvider`) standardizing length 30 + one index policy; widen `orders.status`. New macro = new convention → flag in CLAUDE.md.
- **Verify:** migrations run clean; `partially_refunded` fits. No production data exists, so edit migrations in place — no backfill.

### T-S-STUB — Stub consolidation `[MECHANICAL]` `[biggest LOC win]`
- **Source:** STUB-1/STUB-2. 14 stub models × 5 registration touchpoints, combinatorial. Base `tests/Stubs/StubModel.php` + thin `final` subclasses in a classmap'd `StubModels.php` + `StubColumns.php` per-capability + one `StubModelFactory` with states. Fixes the `unit_price` non-persisted trap (STUB-2) in passing. ~29 files/969 LOC → ~4 files/~350 LOC.
- **Verify:** full suite green (stubs are the test substrate — this is high-blast-radius, do it in isolation). `composer test`.

### T-S-FACTORY — Factory dedupes `[MECHANICAL]` `[LOW]`
- **Source:** scaffolding factory findings. `CreatesAddressPair` trait (CustomerFactory == OrderFactory `withAddresses()`); `Fake::uniqueSlug()` helper (slug fragment in 4-5 factories); pick one of `Currency::EUR` vs `->value`. Also the redundant index+unique on `create_test_localizables_table` `['locale_group_id','locale']`.

### T-S-PROVIDER — Provider consistency `[DECISION-lite]` `[LOW]`
- **Source:** SP-1/SP-2. Move `PaymentServiceProvider` onto `DomainServiceProvider` (keep the one singleton override, drop ~20 lines). Decide the `publishesConfig()` rule (6 domains true, 5 false, no documented reason). One-line decisions; recommend doing SP-1, documenting SP-2.

---

## DEFERRED — do NOT implement now (listed so they aren't rediscovered as "missing")

- **`Shippable` polymorphism refactor** (DES H1 / dep-graph #1): only when a non-order shipment use case appears. Not split prep.
- **`SharesInventory` contract** (DES / dep-graph #2, removes Inventory→Translation leaf-drift): worth doing as leaf hygiene, but only **when `AdjustStock` is next touched** — don't make a special trip.
- **Filament Plugin + registry-resolved `$model`** (DES H2 remainder): before consumer 3. (The default-deny policy half is T-SEC2, do that now.)
- **Promote HandlePaymentSucceeded/Failed into the package** (DES H3): before consumer 3.
- **DL-L1** (`ReservationStatus::active()` + scopes): when a 3rd reservation status lands.
- **DL-L2** (`RetentionPruneCommand` base): when Purchasing adds a 3rd prune command.
- **DL-L3** (`ReconciliationReport` interface): when an aggregate `shop:reconcile` command lands.
- **DL-L8** (`MoneyShape::array()` helper), **DL-L9** (transition-exception message unification), **MIG-2** (Payment `morphs()` cleanup), **FIL-3** (`PackageCreateRecord`): cosmetic; do opportunistically when touching the file, not as dedicated tickets.
- **Architecture test** (DES M5): worth building — it's what keeps the tier boundaries honest going forward — but it's net-new scaffolding, schedule it deliberately rather than inline with a fix.
- **`currency.default` home** (DES M3 / T-D3): do after T-B2, as a follow-up that repoints the single resolver.
- **`config/flowchain.php`** (DES M2), **migrations gate hook** (DES M1): cheap maintenance wins, but independent of the above — batch them into a "maintenance" release.

---

## Suggested execution order

1. **Wave 0** — get D1–D5 answered.
2. **Wave 1** (security), starting T-SEC2 (the headline) and T-SEC1.
3. **Wave 2** (bug-first): T-B1, T-B2 — these are latent accounting/total bugs, highest correctness value.
4. **Wave 3**: T-M0 (delete dead code) → T-M1 (factory pass) → the rest of Wave 3 in any order.
5. **Wave 4**: schedule T-S-STUB and T-B-MIG1 in isolation (high blast radius); the rest opportunistically.

Everything in Wave 3 and most of Wave 4 is single-release-safe. Wave 1 T-SEC1/T-SEC2 and the ⚠EXT tickets touch consumer surface — land the consumer-side changes in the same release window and refresh `docs/periphery.md`.
