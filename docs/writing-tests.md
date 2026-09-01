# Writing tests

This package is the safety net for every consuming project. Tests that go green without exercising the behavior their name claims are worse than no tests — they tell future readers a code path is covered when it isn't. Cover the failure paths as carefully as the happy ones, and write each assertion against the behavior, not against a value you fed in.

> Sibling reading: [verb-families.md](verb-families.md) for what each Action verb means; the guidance below leans on those meanings.

---

## The trust principle

**A test must verify what its name claims.** If the name says "rejects X when Y", asserting only that an exception was thrown is half a test — assert also that no side-effect occurred (no row written, no event dispatched, no state mutated). Without that second assertion, a refactor that moves the guard below the mutation will leave the test green and a real bug in production.

The trust check: imagine flipping the order of guard and mutation in the source. Does the test still pass? If yes, it isn't actually proving the guard guarded.

```php
// ❌ Half a test
public function it_throws_on_zero_delta(): void
{
    $this->expectException(InvalidArgumentException::class);
    app(AdjustStock::class)(['delta' => 0, ...]);
}

// ✅ Proves the guard prevented the mutation
public function it_throws_on_zero_delta_without_writing_a_movement(): void
{
    $this->expectException(InvalidArgumentException::class);
    try {
        app(AdjustStock::class)(['delta' => 0, ...]);
    } finally {
        $this->assertSame(0, StockMovement::query()->count());
    }
}
```

---

## Patterns to follow

### Match the test name to the test scope

If the body runs sequentially in one PHP process, the test does not exercise contention — don't put "under contention" / "under load" / "concurrent" in its name. Either the name describes the *sequential* invariant it actually tests, or there's a real concurrency probe. Currently, the package documents lock-based safety in source (`SELECT … FOR UPDATE`) but has no contention probe — that's a known gap, not something to mask with hopeful names.

### Assert event payload, not just dispatch count

`Event::assertDispatched(StockAdjusted::class)` confirms *something* fired. It does not confirm that the right model, the right delta, or the right reason rode along. For events that drive log subscribers or downstream side effects, pass a closure that asserts the payload:

```php
Event::assertDispatched(
    StockAdjusted::class,
    fn (StockAdjusted $event) => $event->stockable->is($stockable)
        && $event->movement->quantity === 6,
);
```

### Avoid round-trip assertions

If a test passes `tax: 1710` into a DTO, runs the action, and asserts `$order->tax === 1710`, it has only proved the value was persisted. The interesting invariant — that line-level distribution sums to the total under non-uniform prices and rates — needs a fixture with non-uniform prices and rates. Pure-math actions (`CalculateTax`, `CalculateTotal`, `CalculateVoucherDiscount`) deserve at least one input combination that would catch a naïve implementation.

### Pin factory states explicitly

`Payment::factory()->create()` relies on the factory default. If the default ever flips, every test that depended on it silently changes meaning. When the test name talks about a specific status (`it_is_unpaid_when_only_pending_payments_exist`), pin the status: `Payment::factory()->pending()->create()`. Same rule for any state your assertion depends on.

### Cover the negative of an optional update

When an action accepts an optional argument that mutates a field (`description`, `note`, `metadata`), test both the positive (passed → updated) *and* the negative (omitted → original preserved). The bug class this catches: a refactor that always writes the passed value, including null, clobbering the existing one.

### Probe that the mutator path actually ran

When the source uses a primitive that is invisible to a happy-path assertion (`lockForUpdate`, a transaction, a database constraint), either probe its presence (e.g. capture the query log and assert `for update` appeared) or accept that the test doesn't cover that primitive and name it accordingly. Don't dress a sequential test as a concurrency proof.

### Default config is part of the contract

Every domain ships a `config/{domain}.php`. Every test that overrides config in `defineEnvironment` is implicitly testing the *override*, not the shipped default. At least one test per domain should run with the shipped config untouched and exercise the documented default behavior — otherwise a regression in `config/*.php` lands silently.

---

## What not to do

- **Don't mock the action under test.** Action tests exist to verify the action's real behavior end-to-end against a real database (SQLite in-memory + `RefreshDatabase`). Mocking a sibling action that the SUT calls is acceptable when the sibling has its own dedicated test; mocking the SUT is never acceptable.
- **Don't mock the database.** SQLite-in-memory is fast enough; `RefreshDatabase` resets state between tests.
- **Don't `assertTrue(true)`** or write assertions that can't fail meaningfully. If you need a "this got here" marker, restructure the test so the act-and-assert phases produce a real assertion.
- **Don't skip or comment out tests.** Either fix them, delete them, or convert them to `markTestIncomplete` with a tracked TODO. A silently-skipped test rots and gets forgotten.
- **Don't assert on a fixture's introspection.** If a test asserts both that the action did the right thing *and* that the test fixture's internal log has a record, the second half tests the fixture, not the production code path. Keep fixture introspection out of behavior tests.
- **Don't lock in an implementation detail as if it were the contract.** If a test name reads as a behavioral claim ("order with no shipments is not complete"), and the answer differs for a meaningful sub-case (digital orders), either narrow the test name to its actual scope or extend the fixture to cover the sub-case. A test name that overstates its scope cements a maybe-bug as truth.

---

## Coverage shape

For each Action, the package's standard suite covers:

1. The happy path — input, output, primary side-effect (row written / state transitioned), event dispatched with correct payload.
2. Each failure path that throws a typed exception — see [CLAUDE.md](../CLAUDE.md#key-patterns) on the exception strategy. Each throw test asserts the exception type **and** the absence of side-effects.
3. Each documented optional argument — both with and without the argument, asserting the correct field is or isn't touched.
4. Idempotency, where claimed by the docblock.
5. The transaction-rollback case, where the action runs inside `DB::transaction` — assert that an outer exception unwinds the action's writes.
6. The events the action does not dispatch on failure (`Event::assertNotDispatched(...)` in the validation-fails test).

For each Listener / log subscriber:

- One parameterized test per subscriber, asserting that each subscribed event maps to a `LogEntry` with the right level, category, and payload. Subscribers are the package's audit-trail surface; an untested handler is a silent dropped log.

For each HTTP endpoint:

- 401 / 403 / 404 / 422 paths each named explicitly.
- 200 path with response shape asserted (status code alone is a half-test).
- Validation rules each named in their own test (`it_rejects_when_quantity_is_zero` rather than one big "validation" omnibus).

For each Command:

- Idempotency (running twice produces the same end state).
- The no-op case (nothing to process → exit 0, no dispatch).
- The work case (records to process → side-effect occurred + event dispatched).

---

## Test layout

- `tests/Feature/{Domain}/{ActionName}Test.php` — one test class per Action; one Test attribute per behavior.
- `tests/Unit/{Domain}/...` — only for pure-function classes that don't touch the database (rare; FlowChain transactions live here).
- `tests/Stubs/Test{X}.php` — minimal models implementing one or more domain contracts, registered in `TestCase::defineEnvironment` morph map. **Cross-domain consumer tests use these stubs; never project-specific models from `in-other-worlds` or any other consumer.**
- Test method names use snake_case after `it_…` / `<verb>_…`, match what a reader skim-greps for, and don't overstate scope.
- **Filament page tests** — the suite does not boot Filament by default. A test that needs a real Resource page (form lifecycle across saves, authorization through Filament's manager) mixes in `Tests\Support\BootsFilament`, which registers the Filament/Livewire providers *in package-discovery order* (that order is load-bearing — see the trait) plus `Tests\Stubs\Filament\TestPanelProvider`. The panel mounts the stub Resources under `tests/Stubs/Filament/`; `StubEditableResource` is the consumer-shaped one whose Create/Edit pages carry `TranslationSchema`, `MediaSchema` and `InventorySchema`, wired the way in-other-worlds and bianka-shop-one wire theirs. Drive it with `Livewire::test(EditStubEditable::class, ['record' => $id])->fillForm([...])->call('save')`; the harness carries form state across `call()`s exactly as a browser does, which is the thing the static `fillFormData`/`saveFormData` tests cannot see. `tests/Feature/Support/Filament/StubEditablePageTest.php` is the reference.

---

## Reference: examples in the suite

Tests that earn their keep — read these before writing new ones in the same module:

- [tests/Feature/Inventory/ReleaseExpiredReservationsTest.php](../tests/Feature/Inventory/ReleaseExpiredReservationsTest.php) — idempotency, non-pending guard, double-release stock-inflation guard, event-dispatched-once. Each name matches its assertion exactly.
- [tests/Feature/Tax/JurisdictionAwareResolutionTest.php](../tests/Feature/Tax/JurisdictionAwareResolutionTest.php) — tests an *invariant* ("non-EU country must not inherit seller's home rate"), not just a behavior.
- [tests/Feature/Payment/IsPaidTest.php](../tests/Feature/Payment/IsPaidTest.php) — covers every payment status with the right assertion; the regression-anchor comment on the partial-refund test earns trust.
- [tests/Unit/FlowChain/FlowChainTransactionTest.php](../tests/Unit/FlowChain/FlowChainTransactionTest.php) — uses a probe table to prove rollback against a real DB rather than mocking out `DB::transaction`.
