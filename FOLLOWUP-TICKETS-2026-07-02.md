# in-other-shops — Follow-up Tickets (after AUDIT-2026-07-02 implementation)

> **Correction (2026-07-02, post-verification):** the original opening line here claimed all tickets were done. That was **false** — four tickets (T-SEC3, T-SEC4, T-B3, T-B4) were silently dropped by the implementing session, and the implemented T-SEC1/T-SEC2 had real gaps (firstParty trust bypass; relation managers left lenient). See `VERIFICATION-2026-07-02.md` for the full audit. The four dropped tickets + the T-SEC1 fix were implemented on branch `fix/verification-2026-07-02`. This doc's deferral/decision content below remains valid.

Most of the security/correctness/DRY tickets from `IMPLEMENTATION-TICKETS-2026-07-02.md` landed via PR #2/#3 (see the correction above for what did not). This doc captures what was **deliberately deferred** and the **open decisions**, with enough context to start cold.

**Read first (same as the parent spec):** `CLAUDE.md` (esp. §"A Modular Monolith With One Real Seam" — no package-split prep), `docs/writing-tests.md` (test-trust rules — the point of T-S-STUB is to keep the substrate honest, so its own tests must be honest), `AUDIT-2026-07-02.md` §STUB-1/STUB-2 (the source findings). Runner: `composer test`.

---

## T-S-STUB — Consolidate the test stubs `[MECHANICAL]` `[biggest LOC win]` `[DO IN ISOLATION]`

**Source:** AUDIT-2026-07-02 STUB-1 / STUB-2.

**Why deferred:** the stubs are the substrate for **all 935 tests**. A subtle regression (a dropped column, a shifted factory default) makes tests pass while *masking* real behavior — the exact trust hazard `writing-tests.md` warns about. It needs a full-suite green **plus a diff review** confirming every stub still persists and exposes what it did. Give it a fresh context so the whole budget is on the design + the audit, not failure-chasing at a session tail.

**Current state (surveyed 2026-07-02):** `tests/Stubs/` = **29 files, 969 LOC** — 14 stub models + 14 factories + a `tests/Stubs/migrations/` dir with 14 create-table migrations. Five registration touchpoints per stub: the model (contracts + `$table` + `casts()` + `newFactory()`), its factory, its migration, the morph-map entry in `tests/TestCase.php::defineEnvironment()`, and (implicitly) the `getPackageProviders` boot order.

The 14 stub models and the contracts each implements:

| Stub | Implements |
| --- | --- |
| `TestBrowsable` | `HasStock`, `HasStorefrontPresence` |
| `TestCartable` | `HasCart`, `HasOrders` |
| `TestLocalizable` | `HasLocaleGroup` |
| `TestMediable` | `HasMedia` |
| `TestPayable` | `HasPayments` |
| `TestPriceable` | `HasPrices` |
| `TestPurchasable` | `HasPurchases` |
| `TestShippableCartable` | `HasCart`, `HasShippability`, `HasTaxCategory` |
| `TestStockable` | `HasStock` |
| `TestStockableCartable` | `HasCart`, `HasStock` |
| `TestStockableLocalizable` | `HasLocaleGroup`, `HasStock` |
| `TestTaxonomized` | `HasCategories`, `HasTags` |
| `TestTranslatable` | `HasTranslations` |
| `TestVariantable` | `HasCart`, `HasPrices`, `HasStock`, `HasVariants` |

Note: unlike the 32 `src/` models (converted to `static $factory` in T-M1), the stubs still use `newFactory()`. That's fine — leave it until this rewrite folds them into one factory.

**Do (target architecture from STUB-1):**
- `tests/Stubs/StubModel.php` — abstract base carrying the shared boilerplate (`$guarded = []`, factory pointer, table resolution).
- `tests/Stubs/StubModels.php` — thin `final` subclasses (classmapped), each declaring only its contracts + `use InteractsWith*` traits + table.
- `tests/Stubs/StubColumns.php` — per-capability column fragments, so the consolidated migration builds each stub's table by composing the fragments its contracts need.
- one `tests/Stubs/StubModelFactory.php` with **states** per capability (replaces the 14 factories).
- Collapse the 14 migrations into one (or a small number) driven by `StubColumns`.
- Rewire the `tests/TestCase.php` morph map to the classmap.

**Fix in passing (STUB-2 — read the AUDIT entry for the exact case):** a stub set a `unit_price` on the model that was **not persisted** by its migration, so reads returned null and a price-path test proved nothing. The consolidated `StubColumns` + `StubModelFactory` must persist every attribute a stub's contract methods read. When rebuilding, cross-check each stub's `get*UnitPrice`/`tracksStock`/etc. against the columns actually created.

**Verify:** full suite green (`composer test`) **and** a manual diff review: for each of the 14 stubs, confirm the new definition (a) implements the same contract set, (b) persists every column the old migration did, (c) the factory default for any attribute an assertion depends on is unchanged (or intentionally corrected — call those out). Do not accept "green" alone here; green with a masked stub is the failure mode.

---

## T-S-FACTORY — Factory dedupes `[MECHANICAL]` `[LOW]` `[fold into T-S-STUB]`

**Source:** AUDIT-2026-07-02 scaffolding factory findings.

**Why folded:** the meat of this (a shared address-pair helper, a slug helper) is **subsumed by T-S-STUB's single `StubModelFactory` with states** — doing it standalone first is throwaway work. Do it as part of T-S-STUB.

Items:
- `CreatesAddressPair` trait — `CustomerFactory` and `OrderFactory::withAddresses()` build the same shipping+billing address pair.
- `Fake::uniqueSlug()` helper — the slug fragment is duplicated across 4–5 factories.
- Pick one of `Currency::EUR` vs `->value` and use it consistently across factories.
- Remove the redundant `index` + `unique` on `['locale_group_id','locale']` in `create_test_localizables_table` (a unique already implies the index).

**Verify:** `composer test`.

---

## Open decisions (one-liners, no code started)

- **DEC-1 — delete `Agent\Agent` facade?** `src/Agent/Agent.php` (a static wrapper over `ToolRegistry`) has **zero callers** in the package, tests, and both consumers (grep-verified). Recommend delete. It's not external surface (registry resolved names aren't surface). One-line removal + a periphery note if kept-vs-dropped is documented.
- **DEC-2 — `publishesConfig()` rule (SP-2).** Across the domain providers, 6 return `publishesConfig() = true` and 5 `false`, with no documented reason. Decide the rule (e.g. "publish iff the domain has a consumer-tunable config") and document it in CLAUDE.md, or normalise. No behavior change required — this is a documentation/consistency decision.

---

## Still deferred by the parent spec (do NOT rediscover as gaps)

These were deferred by `IMPLEMENTATION-TICKETS-2026-07-02.md` §DEFERRED and remain so — see that doc for the "un-defer trigger" on each: `Shippable` polymorphism, `SharesInventory` contract, Filament Plugin + registry `$model`, promote `HandlePaymentSucceeded/Failed`, `ReservationStatus::active()` scopes, `RetentionPruneCommand` base, `ReconciliationReport` interface, the architecture test (DES-M5), `currency.default` home (T-D3, do after T-B2 which is now done), and the cosmetic DL-L8/L9/MIG-2/FIL-3 items.
