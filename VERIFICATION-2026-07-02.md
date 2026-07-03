# Verification of AUDIT-2026-07-02 implementation (commits f1b8560..0935437, PRs #2/#3)

Three adversarial verification passes (security wave / bug-first wave / mechanical wave) over the merged implementation. Full suite: 937 tests green — treated as necessary, not sufficient; every verdict below is diff/grep/vendor-source-based.

## TL;DR

- **The bulk of the work (≈17 of 21 tickets) is implemented correctly**, including the two highest-risk items: the stub consolidation (T-S-STUB — per-stub contract/column/factory-default diff review passed) and the default-deny Filament base (T-SEC2 — vendor-verified fail-closed at Resource level).
- **Four tickets were silently dropped**: T-SEC3, T-SEC4, T-B3, T-B4. No code, no tests, no deferral note. `FOLLOWUP-TICKETS-2026-07-02.md:3` ("the security/correctness/DRY tickets … are done") and the PR narrative **falsely certify them as done**. Two of the four were explicit Wave-0 decisions (D4 → T-B4).
- **Two real security gaps in the implemented tickets**: a bypass in T-SEC1's client-trust prong, and an uncovered relation-manager surface in T-SEC2.
- **One release blocker for bianka**: under default-deny, bianka has no policies and no `Gate::before` — on the next package bump **all ten package Resources go dark** in its admin. The periphery note understates this as "add an Option policy."

## 1. Silently dropped tickets (need implementation or explicit deferral)

| Ticket | State | Notes |
|---|---|---|
| **T-SEC3** stock-level gating | Not implemented | `GetStockLevel` / `ListStockLevels` still return exact `stock_level` + low-stock filtering to any base-scope caller. SEC M-B stands unfixed. |
| **T-SEC4** fail-closed canonical URL | Not implemented | `CanonicalUrl.php` byte-identical to pre-implementation; blank `canonical_url` + OAuth still derives issuer/audience from the request host. |
| **T-B3** UpdatePurchaseOrderStatus | Not implemented | `ReceiveItems` still writes status without `canTransitionTo`; no such action exists; no test. |
| **T-B4** shipment_items restrictOnDelete | Not implemented | `create_shipment_items_table.php` still `cascadeOnDelete()`. **Was an explicit decision (D4).** Fix note: per T-B-MIG1-REVISE's own (correct) rationale, the fix should now be a forward `DROP/ADD CONSTRAINT` migration, not the in-place edit the original ticket text prescribed. |

Also: `FOLLOWUP-TICKETS-2026-07-02.md:3` must be corrected — it retires these four without them existing.

## 2. Gaps in implemented security tickets

### T-SEC1 (admin elevation) — mechanism real, central prong defeated
`clientMayElevate()`'s fallback trust of `confidential + firstParty()` is attacker-satisfiable **via the package's own DCR endpoint**: DCR's default `token_endpoint_auth_method=client_secret_basic` mints confidential clients, DCR clients have no owner, and Passport's `Client::firstParty()` returns true when `owner_id` is empty. So a default self-registered client passes both checks. Against the exact future the ticket hardens for (admin scope accidentally grantable), this does not fail closed.
**Fix shape:** treat `admin_client_ids` as the *only* OAuth elevation grant (empty allowlist = nobody elevates), or exclude DCR-created clients; stop trusting `firstParty()`. The middleware test suite over-mocks exactly this point (`fakeClient(firstParty: false)` encodes the wrong belief); add a test that mints a client through the real DCR controller.
Reachability today: latent — in-other-worlds registers only the `agent` scope. Verified.

### T-SEC2 (default-deny) — Resources solid, RelationManagers left lenient
`PackageResource::$shouldCheckPolicyExistence = false` is vendor-verified fail-closed across all abilities and pages, all 10 resources extend it, and the census test pins ≥10. **But** the 8 shipped RelationManagers keep Filament's lenient default: Create/Edit/Delete on `OrderLine`, `Address`, `Price`, `Media` (no policies anywhere → silent allow for any panel user who can open the host page), and `ShipmentsRelationManager`'s five custom status-transition actions have `->visible()` state checks but no `->authorize()`. Mitigating: reachable only through an authorized host page. Needs: a package RM base flipping the flag (+ policies for the pivot-ish models, incl. `attach`/`detach` abilities on Tag/Category RMs) or a documented decision to accept host-page-implies-related-mutation.

### Consumer impact (release-window blockers)
- **bianka-shop-one**: no `app/Policies/`, no `Gate::before` → next package bump blanks the entire admin. The periphery note says only "add an Option policy" — understated. Bianka needs the full policy set (or a `Gate::before` super-admin bypass) before bumping.
- in-other-worlds: `AbstractBooleanPolicy` already has `reorder()` + `deleteAny()` — covered.
- The reorder-gap correction lives only in periphery.md's changelog line; the T-SEC2 section body still says "no consumer code change needed." Move it into the section body.

## 3. Verified correct (spot-check highlights)

- **T-B1** TaxBreakdownLine consolidation: byte-identical round-trip (keys, int coercion, null handling, ordering); all 4 sites repointed; `TaxSummaryShapeTest` pins the shape with hard values.
- **T-B2** effectiveCurrency/effectiveUnitPrice: exact fallback semantics preserved at all 4 sites incl. the FlowChain step; mixed-cart `sum(line_total)==subtotal` test through real resources. *Gap:* `VariantsSchema.php:179` still reads `commerce.cart.api.default_currency` directly (missed by the original audit's site list), and `Cart::defaultCurrency()`'s docblock overclaims "every resolver routes through here" — will misdirect the queued T-D3. *Pre-existing, not a regression:* a foreign-currency line with no snapshot can still diverge line_total vs subtotal.
- **T-E1** HasLabel: the ONLY changed label in the package is `Partially Refunded` → `Partially refunded` (intended); `color()` arms untouched; ampersand override kept.
- **T-B-MIG1 (revised)**: deliberate, sound deviation — in-place edits to shipped create migrations never re-run for migrated consumers, so the macro is go-forward convention + two additive index migrations; `orders.status` NOT widened (audit's overflow pairing was itself confused: `partially_refunded` is a PaymentStatus value and `payments.status` is 255). Documented in CLAUDE.md + commit.
- **T-S-STUB**: all 14 stubs preserve contract sets, columns (additions all sanctioned: `tracks_stock`, the STUB-2 `unit_price` fix — `testUnitPrice` gone from the tree), factory defaults value-for-value, morph aliases identical. No leftovers.
- **T-M0/M1/M2, T-A1–A7, T-S1, T-S2, T-S-PROVIDER**: all correct. T-M2 deviates from ticket wording (sync-back stays per-action, ordering-sensitive vs event dispatch) — sound, documented. T-S1 genuinely routes through the dehydrated state (the TipTap fix) with a test pinning it.
- **DEFERRED sweep clean**: nothing from the deferred list snuck in. DEC-1 (`Agent\Agent` facade deleted) was authorized and recorded in periphery.

## 4. Small doc drift (one-liners)

1. `CLAUDE.md` §Coding Standards still says "`newFactory()` on the model points into the package namespace" — stale after T-M1 (`static $factory` now).
2. `DomainServiceProvider` docblock still lists Payment among providers "deliberately NOT adopted" — false after T-S-PROVIDER.
3. `Fake::uniqueSlug()` (folded T-S-FACTORY item) silently skipped — slug fragment still duplicated in 4 factories. Do or record the skip.
4. `Cart::defaultCurrency()` docblock overclaim (see T-B2).
5. `FOLLOWUP-TICKETS-2026-07-02.md:3` false "done" claim (see §1).

## Suggested follow-up order

1. **Before bianka's next package bump:** the bianka policy set (release blocker).
2. **T-SEC3 + T-SEC4** (small, self-contained, already specced).
3. **T-SEC1 allowlist-only fix** + a real-DCR-client test.
4. **T-B3, T-B4** (T-B4 as a forward constraint migration).
5. **T-SEC2 relation-manager decision** (flip + policies, or document acceptance).
6. Doc one-liners (§4).
