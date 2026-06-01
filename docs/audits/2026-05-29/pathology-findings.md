# Pathology Findings — 2026-05-29

Findings from three pathology-reviewer runs against `in-other-shops`, executed at the end of a session that (a) renamed `data-flows.md` → `periphery.md` with an added External surface section, (b) added six new rules to `~/.claude/agents/pathology-reviewer.md` based on a sibling-session audit handoff. The runs were a validation of the new rules; they also surfaced real bugs the original audit missed.

## Method

Three independent pathology-reviewer subagent runs, each given a target surface and a threat model. Prompts deliberately did **not** name the rules under test, so the validation is honest — the agent had to surface findings from the rulebook alone.

| Run | Target | Threat model | Validation goal |
|---|---|---|---|
| A | Pricing compare-at flow (`src/Pricing/`) | production shop, low scale, pre-launch, Stripe wired | Proposal 4 (cron-owned columns) should surface the strikethrough sync-wipe risk |
| B | Taxonomy denormalization (`src/Taxonomy/`, `category_morph_counts`) | production shop, low scale, real category data | Proposal 5 (delta-update drift) should surface the count-drift risk |
| C | v0.23.0 fix (`SyncInventoryOnOrderStatusChange` + broadened `ReleaseReservation`) | production shop, low scale, Stripe wired | Does the fix itself have pathology the original audit missed? |

Rule reference: [`~/.claude/agents/pathology-reviewer.md`](/home/jelte/.claude/agents/pathology-reviewer.md) — six new bullets added 2026-05-29.

## Severity scale

| Tag | Meaning |
|---|---|
| **high** | Real bug or near-certain regression risk; fix before next release. |
| **medium** | Latent risk or partial-failure mode; fix before launch / before scale. |
| **low** | Doc drift, code smell, defensive-coding gap. Fix opportunistically. |

| Status | Meaning |
|---|---|
| **OPEN** | No action taken; needs design + code change. |
| **FIXED 2026-05-29** | Patched in this session. |
| **DECIDE** | Pinned for a choice between two equally defensible directions. |
| **TEST** | Test trust gap or missing-coverage finding; can be closed without changing production code. |

---

## Pricing domain (Run A)

### A-1. [high, user-facing] DST / app-timezone drift on `compare_at_until` — PARTIAL 2026-05-29 (picker pinned to app tz; residual is consumer app.timezone config)

Evidence:
- [`src/Pricing/Database/Migrations/2026_05_14_000001_add_compare_at_until_to_prices_table.php:14`](../../../src/Pricing/Database/Migrations/2026_05_14_000001_add_compare_at_until_to_prices_table.php#L14) — plain `timestamp` column, no per-field timezone
- [`src/Pricing/Filament/PricingSchema.php:110-118`](../../../src/Pricing/Filament/PricingSchema.php#L110-L118) — `DateTimePicker` with no `->timezone()`
- [`src/Pricing/Actions/ExpireCompareAtPrices.php:33`](../../../src/Pricing/Actions/ExpireCompareAtPrices.php#L33) — `now()` against the column

Failure mode: Berlin admin sets "strikethrough ends Friday 17:00" in the Filament picker. With `app.timezone = UTC`, the picker submits `2026-05-29 17:00` and Carbon parses it as UTC. The scheduler's `now()` is also UTC. Customer-visible effect: strikethrough actually ends at UTC 17:00 = Berlin 19:00 (CET) / 19:00 (CEST). The `after('now')` validator and the scheduler are consistent with each other, which masks the bug — admin sees a "valid future date" and nothing complains until the strikethrough clears one to two hours late. If a consumer sets `app.timezone=Europe/Berlin`, the asymmetry inverts and DST-transition weekends produce a one-hour off-by-one.

Probe: in tinker under `config(['app.timezone' => 'UTC'])`, create a price with `compare_at_until = Carbon::parse('2026-07-15 17:00')` (Berlin 19:00 CEST), then run `php artisan pricing:expire-compare-at` at Berlin-local 17:30 — verify it expires (it will, but it shouldn't if the admin meant 17:00 Berlin).

Fix shape: explicit `->timezone(config('app.timezone'))` on the Filament picker AND either pin `app.timezone` per-consumer or store the value with an explicit zone (`timestampTz` column). The cleanest is the per-field timezone on the picker since the column is fine as long as both write and read paths agree.

### A-2. [medium, user-facing] Scheduler-vs-admin TOCTOU undoes a promotion — MITIGATED 2026-05-29 (existing `->after('now')` already rejects the stale save; no code change)

Evidence:
- [`src/Pricing/Actions/ExpireCompareAtPrices.php:45-49`](../../../src/Pricing/Actions/ExpireCompareAtPrices.php#L45-L49)
- [`src/Pricing/Filament/RelationManagers/PricesRelationManager.php:69-73`](../../../src/Pricing/Filament/RelationManagers/PricesRelationManager.php#L69-L73) — no optimistic locking on `UpdatePrice`

Failure mode: admin opens a price edit form at 12:59 showing `amount=4000, compare_at_amount=5000, compare_at_until=13:00`. At 13:00 the scheduler runs and promotes the row to `amount=5000, compare_at_amount=null, compare_at_until=null`. Admin clicks Save at 13:01 — `UpdatePrice` writes the form's stale values back, silently undoing the promotion. Next hour the scheduler promotes again; if the admin pattern recurs, the strikethrough is effectively perpetual.

This is **Proposal 4** (Concurrency: cron-owned columns) applied. The rule surfaced it.

Probe: open the Filament edit form for a price with `compare_at_until` one minute in the future, wait for the scheduler to promote, click Save without touching anything — confirm the database is reverted.

Fix shape: optimistic-locking on `prices` (version column or `updated_at` checked in the UPDATE), Filament edit page detects stale form and reloads. Cheaper interim: warn the admin if `compare_at_until` is now in the past at submit time.

### A-3. [medium, user-facing] Expiry skips orphan `compare_at_until`, leaving rows permanently in a half-state — FIXED 2026-05-29 (rejected at PriceData write boundary)

Evidence:
- [`src/Pricing/Actions/ExpireCompareAtPrices.php:30-34`](../../../src/Pricing/Actions/ExpireCompareAtPrices.php#L30-L34) — filter requires both columns non-null
- [`tests/Feature/Pricing/ExpireCompareAtPricesTest.php:82-94`](../../../tests/Feature/Pricing/ExpireCompareAtPricesTest.php#L82-L94) — pins this as "defensive" behavior

Failure mode: a row with `compare_at_until` set and `compare_at_amount=null` is reachable via direct DB write, via an `UpdatePrice(compareAtAmount: null, compareAtUntil: someDate)` call, via a migration that nulled one column without the other, or via a Filament admin clearing the strikethrough without clearing its end date. The orphan timestamp sits forever; the row never gets cleaned by `pricing:expire-compare-at`. If any consumer reads `compare_at_until` as a "sale ends" countdown attached to a non-existent strikethrough, the storefront shows nonsense.

Probe: `UPDATE prices SET compare_at_amount=NULL WHERE id=X` on a row with `compare_at_until` set; run the command; confirm `compare_at_until` is unchanged. Then audit both consumers for any countdown widget reading `compare_at_until` independently of `compare_at_amount`.

Fix shape: either broaden the expiry to also clean orphan timestamps, or reject the orphan state at write time (`UpdatePrice`/`CreatePrice` validate that the two compare-at columns are set/cleared together).

### A-4. [medium, user-facing] Expiry-driven amount change does not dispatch `PriceUpdated` — FIXED 2026-05-29 (expiry dispatches both; PriceUpdated.fromExpiry suppresses double-log)

Evidence:
- [`src/Pricing/Actions/ExpireCompareAtPrices.php:45-51`](../../../src/Pricing/Actions/ExpireCompareAtPrices.php#L45-L51) — dispatches only `CompareAtPriceExpired`
- [`src/Pricing/Actions/UpdatePrice.php:24`](../../../src/Pricing/Actions/UpdatePrice.php#L24) — only emitter of `PriceUpdated`
- Periphery doc [`docs/periphery.md:93`](../../periphery.md#L93) — lists `PriceUpdated` as the public price-change event consumers may subscribe to

Failure mode: a downstream listener wired to `PriceUpdated` (storefront cache invalidation, search index sync, denormalized `min_price` cache on a Product, marketing webhook) does NOT run when the scheduler promotes a strikethrough. The price displayed on the storefront silently disagrees with the DB until the listener is re-triggered by something else. Both consumers are `HasPrices` users and either could land such a listener.

This is **Proposal 2** (state-mutation path without the canonical event dispatch) applied.

Probe: subscribe a no-op listener to `PriceUpdated` that increments a counter; trigger the expiry on a row with `compare_at_until` past; assert the counter — it will not increment.

Decision needed: is the periphery doc's contract "`PriceUpdated` fires on every `prices.amount` write" (bug in the action — also dispatch `PriceUpdated`) OR "the expiry path is its own channel and consumers must subscribe to both events" (doc needs a callout)? The first is more honest to the event's name; the second avoids double-logging.

### A-5. [low, contained] `PriceData` accepts a past `compareAtUntil` programmatically — FIXED 2026-05-29 (rejected at PriceData constructor)

Evidence: [`src/Pricing/DTOs/PriceData.php:21`](../../../src/Pricing/DTOs/PriceData.php#L21) — `?DateTimeInterface $compareAtUntil = null` with no validation. The Filament rule `->after('now')` at [`PricingSchema.php:115`](../../../src/Pricing/Filament/PricingSchema.php#L115) catches this only for the form; programmatic callers accept arbitrary dates.

Failure mode: an import script or agent tool sets `compare_at_until = yesterday` with a valid `compare_at_amount`. The row is in a "already expired but not yet swept" state. Customers see the strikethrough until the next hourly scheduler run — up to 59 minutes of false advertising, exactly what the EU-Omnibus tooltip is preventing.

Probe: `(new CreatePrice)($priceable, new PriceData(amount: 4000, currency: Currency::EUR, compareAtAmount: 5000, compareAtUntil: now()->subDay()))` — confirm it succeeds.

Decision needed: reject at DTO (typed validation), or accept-and-let-the-scheduler-clean-up. Either is defensible; current behavior is accidental.

---

## Taxonomy domain (Run B)

### B-1. [high, systemic] Filament `DetachBulkAction` bypasses events entirely — FIXED 2026-05-29

Evidence: [`src/Taxonomy/Filament/RelationManagers/CategoriesRelationManager.php:96-99`](../../../src/Taxonomy/Filament/RelationManagers/CategoriesRelationManager.php#L96-L99) — `BulkActionGroup` wraps only `DetachBulkAction::make()` with no `->after()` hook. The single-row `DetachAction` at line 86 dispatches `CategoryDetached`; the bulk variant does not.

Failure mode: admin selects multiple categories on a product/bundle/content edit page and clicks bulk-detach. Filament executes the pivot deletes; no event fires; `category_morph_counts` is not decremented. Filter UI keeps reporting the categories as populated. The README explicitly warns against raw pivot writes — **the package's own UI does exactly that**.

This is **Proposal 2** (state-mutation path skipping event dispatch) applied. The agent surfaced an undiscovered real bug.

Probe: in a Filament feature test, attach three categories to a `TestTaxonomized` model, drive the bulk-detach action against two of them; assert `category_morph_counts` rows go to zero. They will not.

Fix shape: wrap `DetachBulkAction::make()->after(fn ($records, $owner) => $records->each(fn ($cat) => CategoryDetached::dispatch($owner, $cat)))` — or replace the helper with a project-owned bulk action that goes through the `DetachCategory` action.

### B-2. [high, systemic] Pivot write commits then counts listener can leave ancestors half-updated on failure — FIXED 2026-05-29

Evidence:
- [`src/Taxonomy/Actions/AttachCategory.php:16-18`](../../../src/Taxonomy/Actions/AttachCategory.php#L16-L18) — `attach` then dispatch, no transaction
- [`src/Taxonomy/Actions/DetachCategory.php:16-22`](../../../src/Taxonomy/Actions/DetachCategory.php#L16-L22)
- [`src/Taxonomy/Listeners/MaintainCategoryCounts.php:44-46`](../../../src/Taxonomy/Listeners/MaintainCategoryCounts.php#L44-L46) — synchronous walk with one UPSERT per ancestor

Failure mode: `attach` commits immediately. Listener walks ancestors with one UPSERT per level. If any UPSERT throws partway (deadlock, lock-wait timeout, connection blip, the 64-char `morph_alias` truncation in B-3, an `applyDelta` query failing on a parent row), the pivot row is persisted but some ancestors are incremented and some are not. Exception propagates to the caller; pivot write is NOT rolled back. Same shape applies to `DetachCategory`, the `updated` hook on Category (the `updated` event fires after commit), and `deleting`. Subsequent attach/detach operations layer further deltas on drifted rows. Drift accumulates silently until somebody runs `taxonomy:recompute-category-counts` — and the recompute itself has B-4.

This is **Proposal 5** (delta-update aggregate drift) applied.

Probe: in a test, stub `DB::statement` on the second `applyDelta` call to throw; run `(new AttachCategory)(...)` against a 3-level tree; assert the pivot row exists AND the first ancestor's count was incremented while the third was not.

Fix shape: wrap `AttachCategory::__invoke` / `DetachCategory::__invoke` / the model `updated`/`deleting` flows in `DB::transaction(...)` that also encloses the ancestor walk — pivot write and count updates must commit atomically. Alternative: queue the count maintenance as a job that re-derives from the pivot rather than incrementing, eliminating the drift class entirely.

### B-3. [high, systemic] 64-char `morph_alias` column silently truncates or throws — FIXED 2026-05-29

Evidence:
- [`src/Taxonomy/Database/Migrations/2026_05_11_000001_create_category_morph_counts_table.php:16`](../../../src/Taxonomy/Database/Migrations/2026_05_11_000001_create_category_morph_counts_table.php#L16) — `->string('morph_alias', 64)`
- [`src/Taxonomy/Listeners/MaintainCategoryCounts.php:42`](../../../src/Taxonomy/Listeners/MaintainCategoryCounts.php#L42) — `$event->model->getMorphClass()` with no length check
- [`src/Taxonomy/README.md:115-126`](../../../src/Taxonomy/README.md#L115-L126) — warns about morph-map requirement but no defensive code

Failure mode: a consumer (or a future domain inside a consumer) forgets to register `Relation::morphMap`. `getMorphClass()` returns the FQCN. The `categorizables.categorizable_type` column has no length limit (Laravel default 255), so the pivot write succeeds, but `category_morph_counts.morph_alias` is 64. On MySQL with strict mode off (default for many shops): silent truncation, the truncated alias does not match the FQCN stored in the pivot, recompute and incremental updates diverge permanently. On MySQL strict mode: the UPSERT throws and the whole attach action raises — pivot row still committed (see B-2). Both consumers are pre-launch so it's recoverable now; a new consumer with deep namespaces (Bianka's planned `Bianka\Project\Variants\Models\ProductOptionValue` is 50 chars and growing) hits this on first attach.

Probe: a test that attaches a model whose `getMorphClass()` returns a 65-char string and asserts the listener either rejects it explicitly or that the persisted alias matches the pivot's `categorizable_type`.

Fix shape: bump the column to `varchar(255)` to match the pivot, AND add a defensive guard in `MaintainCategoryCounts` that throws on length mismatch with a clear "register the morph map" error message. The migration bump is non-breaking; the guard is the durable safety net.

### B-4. [medium, systemic] Recompute command races with concurrent writes — FIXED 2026-05-29

Evidence: [`src/Taxonomy/Commands/RecomputeCategoryCountsCommand.php:26-81`](../../../src/Taxonomy/Commands/RecomputeCategoryCountsCommand.php#L26-L81) — `DB::transaction(... delete then bulk insert ...)`, no table lock, no advisory lock, final write is `DB::table('category_morph_counts')->insert($rows)` (no upsert).

Failure mode: ops runs `taxonomy:recompute-category-counts` to repair drift. Inside the transaction: DELETE all rows, bulk INSERT. A concurrent admin attach fires the listener, which UPSERTs onto `(category_id, morph_alias)` PK. If the listener's UPSERT lands after the transaction's DELETE but before its INSERT commits, the listener creates a row the transaction's INSERT then collides with (the bulk INSERT is not on-conflict). Command throws, transaction rolls back; the listener's row remains. Even without collision, a concurrent attach mid-recompute double-counts: the rebuild aggregates the now-present pivot row, then the listener's UPSERT adds 1 on top of the rebuilt value. Either outcome breaks the "idempotent recovery" promise.

The **recovery tool corrupts state under concurrent load**, which is precisely the condition under which it's used.

This is **Proposal 5** (delta-update drift — the recompute needs to be safe under concurrency, not just exist) applied at the meta-level.

Probe: spawn a concurrent process that calls `AttachCategory` in a tight loop on a fixed category; run `taxonomy:recompute-category-counts` against a populated tree; assert final counts equal the pivot aggregation. They will not on some runs.

Fix shape: acquire an advisory lock around the recompute (`SELECT GET_LOCK(...)` on MySQL, `pg_advisory_lock` on Postgres) so concurrent listener calls queue; OR use `INSERT ... ON DUPLICATE KEY UPDATE` semantics for the final write so collisions are resolved deterministically. The lock approach is simpler and matches the "recovery tool, used rarely" usage pattern.

### B-5. [medium, contained] Migration backfill has no cycle guard — PARTIAL 2026-05-29 (shared CategoryAncestry helper now backs the listener + recompute walks; migration backfill guard not applied — editing the tracked migration is guardrailed, and the backfill no-ops on a fresh install where categorizables is empty)

Evidence:
- [`src/Taxonomy/Database/Migrations/2026_05_11_000001_create_category_morph_counts_table.php:48-58`](../../../src/Taxonomy/Database/Migrations/2026_05_11_000001_create_category_morph_counts_table.php#L48-L58) — `while ($current !== null)` with no `$seen` set
- compare to [`src/Taxonomy/Commands/RecomputeCategoryCountsCommand.php:50-63`](../../../src/Taxonomy/Commands/RecomputeCategoryCountsCommand.php#L50-L63) and [`src/Taxonomy/Listeners/MaintainCategoryCounts.php` `walkAncestors`](../../../src/Taxonomy/Listeners/MaintainCategoryCounts.php) — both guard cycles

Failure mode: a consumer has a parent_id cycle in `categories` from any source (an admin Select that didn't validate self-parenting, a raw seeder, a previous bug, a manual SQL fix). On first deploy of the package, the migration runs the backfill, hits the cycle, spins forever until the deploy timeout kills it. On a Coolify deploy this hangs the rollout — manual `migrate:rollback` or DB intervention required.

Probe: insert a cycle into a fresh `categories` table by raw SQL, then run the migration. The recompute command guards this; the migration does not.

Fix shape: lift the cycle guard from `walkAncestors` into a shared helper and use it in all three places. The drift between three implementations of the same walk is itself a smell.

### B-6. [medium, user-facing] `onMoved` reads its delta from `category_morph_counts` itself — drift propagates instead of self-correcting — FIXED 2026-05-29 (onMoved re-derives the subtree total from the categorizables pivot)

Evidence: [`src/Taxonomy/Listeners/MaintainCategoryCounts.php:60`](../../../src/Taxonomy/Listeners/MaintainCategoryCounts.php#L60) — `$counts = $this->loadCounts(...)` reads from `category_morph_counts`.

Failure mode: counts have drifted for any reason (B-2, B-4, a previous bug, a seeder that bypassed events). Drift is currently localized to the affected subtree. Admin moves a node whose counts row is wrong. Listener reads the wrong value, decrements the old ancestor chain by the wrong amount, increments the new ancestor chain by the wrong amount. Drift now spans **two** ancestor chains instead of one. Each subsequent move that touches a drifted node amplifies the spread. Recompute fixes everything in one shot — but the README markets recompute as "only needed if the invariant ever drifts," implying it's a rare event. In practice the listener actively spreads drift whenever drift is present.

Probe: manually `UPDATE category_morph_counts SET count = count + 5 WHERE category_id = X` on a leaf, move that leaf to a different parent, assert old AND new ancestor chains both reflect the spurious +5.

Fix shape: derive the move delta from the source of truth (`categorizables` count via a fresh query), not from the drifted aggregate. This makes the move handler self-correcting in the presence of existing drift instead of amplifying it.

### B-7. [medium, contained] `walkAncestors` does N+1 SELECTs per ancestor — FIXED 2026-05-29 (listener preloads the parent map once via CategoryAncestry; one SELECT replaces the per-ancestor N+1)

Evidence: [`src/Taxonomy/Listeners/MaintainCategoryCounts.php:113`](../../../src/Taxonomy/Listeners/MaintainCategoryCounts.php#L113) — `DB::table('categories')->where('id', $current)->value('parent_id')` inside the while-loop.

Failure mode: for a tree of depth D, every single-item attach triggers D `SELECT parent_id` queries + D upserts = 2D round-trips, none batched. Threat model is low-scale so absolute cost is fine; the bigger issue is what this does inside FlowChain checkout transactions — every additional query inside the cart-side transaction stretches the lock-hold window.

Probe: enable query log, run `(new AttachCategory)($model, $leafCategory)` against a 5-deep tree, assert query count.

Fix shape: a single up-front recursive CTE (`WITH RECURSIVE`) loads the full ancestor chain in one query. The cycle guard becomes "stop when an ID repeats in the CTE result."

---

## v0.23.0 fix self-review (Run C)

### C-1. [medium, user-facing] `UpdateOrderStatus` commits the status update before the listener runs, with no transactional wrap — the sync-wipe shape being fixed re-emerges as a partial-failure — FIXED 2026-05-29

Evidence: [`src/Commerce/Order/Actions/UpdateOrderStatus.php:20-22`](../../../src/Commerce/Order/Actions/UpdateOrderStatus.php#L20-L22) — `$order->update([...])` commits, then `OrderStatusChanged::dispatch(...)` synchronously invokes the listener.

Failure mode: admin cancels a Confirmed order. `$order->update` commits `status=Cancelled`. The listener begins `releaseReservationsFor`, runs the SELECT, starts iterating; mid-loop the DB connection drops, or `AdjustStock` deadlocks, or any downstream event listener (`ReservationReleased`, `StockReleased`, log subscriber) throws. Exception propagates; Filament shows a 500; admin retries — but the order is *already* `Cancelled`, so `validateTransition` rejects the retry (`Cancelled` has no allowed transitions). **Order is now Cancelled with reservations stuck in Confirmed forever. Identical end state to the bug v0.23.0 shipped to fix.** The listener's idempotency doesn't help because there's no re-trigger path.

Probe: register a second `OrderStatusChanged` listener that throws after the package listener returns (or wrap `ReleaseReservation` to throw on the second iteration), call `UpdateOrderStatus`, assert `Order::status === Cancelled` AND reservation still `Confirmed`.

Fix shape: wrap the status update + dispatch + listener execution in `DB::transaction(...)`. The synchronous listener runs inside the transaction; any listener throw rolls back the status change. The cost is admins seeing transient failures on retry — but a failed write is a recoverable state, while a half-written cancellation is not.

### C-2. [medium, contained] Two trigger paths in a webhook-retry window both pass `validateTransition` and both dispatch the event — FIXED 2026-05-29 (UpdateOrderStatus locks + re-reads the row; same-status is an idempotent no-op. Note: the webhook path was already idempotent at the Payment `webhook_events` ledger; this hardens every other concurrent caller)

Evidence: [`src/Commerce/Order/Actions/UpdateOrderStatus.php:14-25`](../../../src/Commerce/Order/Actions/UpdateOrderStatus.php#L14-L25) — no `lockForUpdate` on the order row, no transaction. "Check status" and "write status" are non-atomic.

Failure mode: Stripe sends two `payment_intent.succeeded` webhooks (Stripe's normal retry-until-2xx when the first response is slow). Both `HandlePaymentSucceeded` invocations resolve the same order, both call `ConfirmReservation` (the second is a no-op), both read `status === Pending`, both call `UpdateOrderStatus(Confirmed)`. Both pass `canTransitionTo(Pending→Confirmed)`, both commit, both dispatch. Listener fires twice — second call is a no-op via `ConfirmReservation`'s status guard. **But `CommerceLogSubscriber::handleOrderStatusChanged` logs twice; consumer `HandlePaymentSucceeded:39` queues `OrderConfirmation` twice. Customer gets two confirmation emails.**

Probe: run two `HandlePaymentSucceeded` invocations against the same Pending order concurrently, assert exactly one log entry and exactly one mail dispatch.

Fix shape: `lockForUpdate` on the order row inside `UpdateOrderStatus` so the second caller waits for the first to commit, then sees the new status and short-circuits. Alternatively / additionally: webhook deduplication at the package's `webhook_events` table (already present for the event-id; might need to also dedupe at the order-level for the cascade).

### C-3. [low, contained] Listener's `releaseReservationsFor` reads the reservation set outside any transaction — DOCUMENTED 2026-05-29 (narrowed by C-1's transaction; the proper guard — reject reservations for a non-Pending order — belongs in the consumer's checkout ReserveItems step, not the package's reference-agnostic ReserveStock, which must not depend on Commerce/Order. No package code change; flagged for the consumer checkout flow)

Evidence: [`src/Commerce/Order/Listeners/SyncInventoryOnOrderStatusChange.php:57-65`](../../../src/Commerce/Order/Listeners/SyncInventoryOnOrderStatusChange.php#L57-L65) — `whereIn('status', [...])->get()` outside any transaction; per-row `ReleaseReservation` locks individually.

Failure mode: listener selects reservations [A, B] for order. Between SELECT and loop start, a concurrent path creates reservation C for the same (already-Cancelled) order (e.g., a delayed `ReserveStock` job firing late). C never gets released — the listener already snapshotted the set. The "already-Cancelled order shouldn't be receiving reservations" invariant is held by application code that's not enforced anywhere in the package.

Probe: search the codebase for any path that calls `ReserveStock` without first asserting `$order->status === Pending`. The `Checkout/Steps/ReserveItems` flow doesn't guard against this.

Fix shape: subsumed by C-1 (the transaction would include the SELECT, eliminating the race). If C-1 isn't taken, add an invariant guard at `ReserveStock` rejecting reservations for non-Pending orders.

---

## Periphery doc drift — FIXED 2026-05-29

These were surfaced by Proposal 6 (Periphery discovery scope rule) doing its job — pathology-reviewer cross-checked the periphery doc against code and found mismatches.

### D-1. `pricing:expire-compare-at` row understated what the command writes — FIXED

Evidence: [`docs/periphery.md` Auto-scheduled commands row](../../periphery.md) — previously said "nulls `compare_at_amount`, dispatches `CompareAtPriceExpired`."

Reality (per [`src/Pricing/Actions/ExpireCompareAtPrices.php:45-49`](../../../src/Pricing/Actions/ExpireCompareAtPrices.php#L45-L49)): also rewrites `amount` from `compare_at_amount` and nulls `compare_at_until`.

Fixed 2026-05-29: row now accurately describes the promotion + dual-null + carries-pre-promotion-amount, with a note that `prices.amount` is effectively cron-owned on the hour.

### D-2. `PricingLogSubscriber` channel mismatch — RESOLVED 2026-05-29 (kept `commerce`, intentional)

Evidence: previously said `domain_logs (pricing channel)`. Reality at [`src/Pricing/Listeners/PricingLogSubscriber.php:19`](../../../src/Pricing/Listeners/PricingLogSubscriber.php#L19): `private const string CHANNEL = 'commerce';`.

Every other LogSubscriber follows a per-domain channel pattern (`AgentLogSubscriber → agent`, `CommerceLogSubscriber → commerce`, `InventoryLogSubscriber → inventory`, etc.). `PricingLogSubscriber → commerce` is the outlier — almost certainly drift.

Fixed in doc 2026-05-29: row updated to `'commerce'` with an explicit "flagged for code fix" annotation. **Code fix is open** — one-line change to `PricingLogSubscriber.php:19`, but it'd need to be made deliberately since changing a log channel could affect anything currently filtering by `channel='commerce'` in `domain_logs` analytics.

---

## Test trust + missing coverage backlog — TEST

A consolidated list. Each is a test-only change; production code stays put.

| Tag | Run | Class | Evidence | Fix |
|---|---|---|---|---|
| T-1 | C | `it_is_idempotent_when_a_consumer_listener_already_did_the_work` only covers the Confirm side, not the Release side | [`tests/Feature/Commerce/Order/SyncInventoryOnOrderStatusChangeTest.php:114-138`](../../../tests/Feature/Commerce/Order/SyncInventoryOnOrderStatusChangeTest.php#L114-L138) | Add a sibling test that exercises the Cancelled path twice and asserts the second call is a no-op. |
| T-2 | C | "fires automatically when UpdateOrderStatus dispatches the event" only covers `Pending → Confirmed`, not the `Confirmed → Cancelled` admin path that was the original v0.23.0 regression | [`tests/Feature/Commerce/Order/SyncInventoryOnOrderStatusChangeTest.php:141-158`](../../../tests/Feature/Commerce/Order/SyncInventoryOnOrderStatusChangeTest.php#L141-L158) | Mirror the test with `OrderStatusChanged(Confirmed → Cancelled)` arrived at via `UpdateOrderStatus`, assert stock restored. |
| T-3 | C | Confirmed-reservation fixture reached via `$reservation->update(['status' => Confirmed])` instead of `ConfirmReservation` | [`tests/Feature/Commerce/Order/SyncInventoryOnOrderStatusChangeTest.php:100-101`](../../../tests/Feature/Commerce/Order/SyncInventoryOnOrderStatusChangeTest.php#L100-L101) | Replace with `(new ConfirmReservation)($order)` so fixture matches production lifecycle. |
| T-4 | C | No direct `ReleaseReservationTest.php` — the entire v0.23.0 broadening is only exercised transitively via the listener test | no such file | New test class asserting Confirmed-reservation release writes the ledger entry, sets `release_movement_id`, dispatches both events, sets `resolved_at`, returns `null` on re-release. |
| T-5 | C | No multi-reservation cardinality test | listener loop at [`SyncInventoryOnOrderStatusChange.php:63-65`](../../../src/Commerce/Order/Listeners/SyncInventoryOnOrderStatusChange.php#L63-L65) — all five tests use one reservation | One test with two reservations confirms both flip; one test forcing partial failure on the second exposes the partial-release class. |
| T-6 | C | No test asserts `UpdateOrderStatus` does NOT dispatch on `validateTransition` failure | [`UpdateOrderStatus.php:16-22`](../../../src/Commerce/Order/Actions/UpdateOrderStatus.php#L16-L22) | `Event::fake([OrderStatusChanged::class])`, invoke `UpdateOrderStatus` on an invalid transition, assert exception + no event. |
| T-7 | B | `recompute_command_is_idempotent` proves the trivial case — single-threaded, stable data | [`tests/Feature/Taxonomy/MaintainCategoryCountsTest.php:245-257`](../../../tests/Feature/Taxonomy/MaintainCategoryCountsTest.php#L245-L257) | Add a drifted-starting-state test and a concurrent-write test. See B-4 fix. |
| T-8 | B | `a_parent_loop_does_not_send_the_listener_into_an_infinite_walk` only covers `onAttached`, not `onMoved` or the migration backfill | [`tests/Feature/Taxonomy/MaintainCategoryCountsTest.php:209-226`](../../../tests/Feature/Taxonomy/MaintainCategoryCountsTest.php#L209-L226) | Sibling tests for the other two walk implementations (see B-5 — shared helper fix). |
| T-9 | B | `deleting_a_category_with_children_throws_and_leaves_state_untouched` does not assert `categorizables` is unchanged | [`tests/Feature/Taxonomy/MaintainCategoryCountsTest.php:179-206`](../../../tests/Feature/Taxonomy/MaintainCategoryCountsTest.php#L179-L206) | Add `assertSame(1, DB::table('categorizables')->where(...)->count())`. |
| T-10 | B | No test for Filament bulk-detach path (cross-ref B-1) | [`CategoriesRelationManager.php:96-99`](../../../src/Taxonomy/Filament/RelationManagers/CategoriesRelationManager.php#L96-L99) | Drive bulk-detach via Filament feature test, assert counts decremented. |
| T-11 | B | No test for listener failure mid-walk (cross-ref B-2) | [`AttachCategory.php:16-18`](../../../src/Taxonomy/Actions/AttachCategory.php#L16-L18) | Failure-injection test asserting transactional rollback. |
| T-12 | B | No test for over-length morph alias (cross-ref B-3) | [`MaintainCategoryCounts.php:42`](../../../src/Taxonomy/Listeners/MaintainCategoryCounts.php#L42) | Stub model with 70-char `getMorphClass()`, assert defensive guard fires or alias round-trips identically. |
| T-13 | B | No e2e test for migration backfill | [`2026_05_11_000001_create_category_morph_counts_table.php:23`](../../../src/Taxonomy/Database/Migrations/2026_05_11_000001_create_category_morph_counts_table.php#L23) | Seed `categories` + `categorizables`, drop counts table, re-run migration, assert match with recompute output. Add a cycled variant. |
| T-14 | B | No test for raw-update-bypassing-observer move path | README [`src/Taxonomy/README.md:84`](../../../src/Taxonomy/README.md#L84) names this as a drift source | Build tree, bypass observer to move a node, run recompute, perform proper observed move, assert counts converge. |
| T-15 | A | `it_skips_a_row_with_an_end_date_but_no_compare_at_amount` pins A-3 as the spec rather than catching it | [`tests/Feature/Pricing/ExpireCompareAtPricesTest.php:82-94`](../../../tests/Feature/Pricing/ExpireCompareAtPricesTest.php#L82-L94) | Add `assertNull($price->fresh()->compare_at_until)` once A-3 is fixed; the test failing is the bug. |
| T-16 | A | `running_twice_in_succession_is_idempotent` proves count-not-effect | [`tests/Feature/Pricing/ExpireCompareAtPricesTest.php:118-131`](../../../tests/Feature/Pricing/ExpireCompareAtPricesTest.php#L118-L131) | Assert effect (final state) not just count, after both runs. |
| T-17 | A | No test for timezone semantics of `compare_at_until` (cross-ref A-1) | no such test | Set `app.timezone='Europe/Berlin'`, use Berlin-tz timestamps, `Carbon::setTestNow`, assert wall-clock behavior. |
| T-18 | A | No test for expiry-driven `PriceUpdated` dispatch (cross-ref A-4) | no such test | `Event::fake([PriceUpdated::class])`, run expiry, assert whether dispatched. Pins the decision either way. |
| T-19 | A | No test for concurrent admin-edit vs scheduler write (cross-ref A-2) | no such test | Load, expire, save stale form, assert behavior. |
| T-20 | A | No test for `CreatePrice` / `UpdatePrice` accepting past `compareAtUntil` (cross-ref A-5) | no such test | Call with past date, assert chosen behavior. |

---

## Cross-cutting observations

1. **Pivot-write-then-fire-event pattern is a partial-failure trap.** B-2 (taxonomy) and C-1 (order status) are the same shape: commit a write, then synchronously fire listeners whose failure cannot roll back the write. The pattern is widespread; both Run B and Run C surfaced an instance independently. Worth a package-level audit pass for other Action classes following the same shape.

2. **Defensive code drifts across implementations of the same walk.** B-5 (migration backfill has no cycle guard) is a symptom: three different walks of the same parent_id tree have three different defensive postures. The fix shape is "shared helper, single source of truth." This is a small structural debt that should land before split pressure makes it expensive.

3. **The recovery tool needs to be safe under the conditions it's used in.** B-4 (recompute races with concurrent writes) is sharper than the underlying drift problem. Recompute is called precisely when the system is in a degraded state, often with ongoing load. A recovery tool that can corrupt state is worse than no recovery tool because the operator trusts it.

4. **State-change events without subscribers silently drop side-effects.** A-4 (`PriceUpdated` not dispatched from expiry) and B-1 (`CategoryDetached` not dispatched from Filament bulk action) are both instances. Worth grepping the codebase for `dispatch` calls and cross-checking that every state-mutation path goes through one.

5. **The new pathology-reviewer rules earned their keep.** Proposals 2, 4, 5, 6 all surfaced findings in the three runs. The rules are not just documentation — they actively shifted what the agent looks for.

---

## Suggested prioritization

For a single-release-window fix (matches [[feedback_package_breaking_changes]]: single-release-window is fine while consumers are pre-launch):

1. **B-1 (DetachBulkAction)** — straightforward fix, real bug, admin-facing.
2. **C-1 (UpdateOrderStatus transactional)** — same pathology class as the v0.23.0 fix's motivation; embarrassing to ship a fix that re-introduces the same end state. Worth fixing before any further release.
3. **B-3 (morph_alias column)** — schema-touching but additive; bump to 255 + add defensive guard. Cheap and durable.
4. **B-2 (pivot-then-walk transactional)** — bigger refactor (wrap multiple actions); land alongside the B-3 fix if B-3 needs a release anyway.
5. **B-4 (recompute advisory lock)** — small change, big safety win.
6. **D-2 code side** (`PricingLogSubscriber` channel) — decide and ship; doc already reflects code.

The pricing findings (A-1 through A-5) plus B-5/B-6/B-7 are second-pass. None are user-facing today; all become real concerns at scale or post-launch.

Test backlog (T-1 through T-20) can be picked up incrementally and doesn't gate any of the above. Highest-value early tests: T-2 (the missing e2e for the actual v0.23.0 regression case), T-4 (`ReleaseReservationTest` covering the broadening), T-10 (catches B-1 in regression).

---

## Resolution log — 2026-05-29 (release-window set)

The six release-window findings were fixed in one window (consumers pre-launch, single-release posture). All fixes shipped with tests; full suite green (595 tests).

| Finding | Fix | Tests |
|---|---|---|
| **B-1** | `CategoriesRelationManager` bulk-detach now dispatches `CategoryDetached` per record (extracted `detachBulkAction()` + shared `dispatchCategoryDetached()`), matching the single-row action. | `CategoriesRelationManagerBulkDetachTest` (T-10) |
| **B-2** | `AttachCategory`/`DetachCategory` wrap pivot write + count walk in `DB::transaction`. `Category::save()`/`delete()` overridden to wrap the move/delete lifecycle + count reaction atomically (observer paths). | `AttachDetachCategoryTest::a_listener_failure_rolls_back_the_pivot_write` (T-11); `MaintainCategoryCountsTest` move/delete rollback tests |
| **B-3** | New forward migration `2026_05_29_000001_widen_category_morph_counts_morph_alias` (64→255, SQLite-skipped). `MaintainCategoryCounts` guards alias length, throws `MorphAliasTooLongException`. | `MaintainCategoryCountsTest::attach_rejects_a_morph_alias_longer_than_the_counts_column` (T-12) |
| **B-4** | `RecomputeCategoryCountsCommand` takes a cross-driver advisory lock (recompute-vs-recompute) and writes the rebuild via upsert (no crash on a concurrent listener row). Listener hot path untouched — no checkout serialization. Residual: exact correctness under simultaneous heavy writes is not guaranteed (recovery tool, meant for quiet windows). | `MaintainCategoryCountsTest::recompute_command_overwrites_inflated_counts_and_clears_orphan_rows` (T-7) |
| **C-1** | `UpdateOrderStatus` wraps status write + event dispatch + synchronous listeners in `DB::transaction` — a listener failure rolls the status change back to a recoverable state. | `UpdateOrderStatusTest` (rollback, happy-path, invalid-transition); `SyncInventoryOnOrderStatusChangeTest` Confirmed→Cancelled e2e (T-2, T-3) |
| **D-2** | Decided: keep `commerce` channel as the intentional exception (price changes are commercial audit events; no separate `pricing` channel exists). Documented in code + periphery.md. | n/a (decision) |

Deferred (unchanged, still OPEN): pricing A-1…A-5; taxonomy B-5 (migration cycle guard), B-6 (move reads drifted counts), B-7 (N+1 walk); order C-2 (webhook-retry double-dispatch), C-3 (reservation snapshot race). Test backlog T-1, T-4…T-6, T-8, T-9, T-13…T-20 not yet picked up.
