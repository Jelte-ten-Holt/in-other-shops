# Brief — stock holds, and admin orders that take stock

Status: **DONE — released as v0.74.0 and both consumers bumped** (2026-11-04). Package #39;
in-other-worlds #110 (D12) and #111 (bump); bianka-shop-one #37 (bump). Open: the §12 rulings,
and Bianka's review of the Spanish drafts. §12 records the calls
the build made where this brief was silent. Was: **BUILD-READY** (draft 3, 2026-11-04). Decisions D1–D9 were settled with Jelte on 2026-11-04;
critique pass 1 the same day, against the package at v0.73.0 (main) and both consumers,
produced D10–D15 (settled with Jelte) and the changes marked **Δ**; a fresh pathology
review of §4.2 and §4.4 followed and produced the changes marked **Δ3** — above all
one lock order for every reservation actor. §11 is the changelog. §8 holds what is
still open, each with a lean. Next: build, in the order §10 gives. Nothing built.

This **reverses the 2026-10-27 ruling** that admin-created orders stay stock-neutral
(`TODO.md` § Open, bianka `TODO.md`). That ruling asked for a hand procedure
(take the piece offline before a market, then record the sale); this brief
replaces the hand procedure with the mechanism.

**Hand-off — start here.** This brief is the build plan; there is no separate one.

- It lives on the package's local `ride-along` branch only, not on main. Branch
  the package PR from main and merge `ride-along` into it, so the brief lands
  with the code (the ride-along convention).
- Order (§10): (1) IOW's D12 cleanup, its own PR in in-other-worlds, needs
  nothing from the package; (2) the package PR — one PR for all of §4–§6's
  package side — then Jelte tags the release; (3) one PR per consumer against
  the released package.
- §8's leans are what gets built; a different ruling needs Jelte's word.
- Conventions that apply: every new guard checked red on the old code first
  (§7); the MySQL CI leg before tagging (§6); `docs/periphery.md` in all three
  repos; every CI suite green before a push; `docs/verb-families.md` gets the
  `Reserve*` line (Q3).
- Keep clear of the books branches (`feat/books-*`, the other worktrees): a
  parallel stream.
- Update this Status line as milestones land (built on branch / released as
  vX / consumers bumped), as the refund brief did.

## 1. Goal

An admin can set stock aside for a reason ("Markt 15 Nov", "Kept for Ana") so it
can't sell online, and an order created in the admin takes its stock either from
main stock, from one of those holds, or not at all. A market day then works
without hand steps: hold the pieces you pack, record each sale from the hold,
release what comes back.

## 2. How stock works today (facts the design rests on)

Every fact here was re-read in code during the critique; the Δ ones are new or
corrected since draft 1.

- One number per stockable, `stock_items.stock_level`, is what can sell. It is
  net of reservations: **a reservation already means "not for sale"**; there is
  no separate reserved pot.
- `ReserveStock` subtracts at once (a `-N Reserved` movement under the
  `AdjustStock` row lock) and creates a `Pending` `StockReservation` with a
  morph `reference` (today always an order) and a `reserved_until`.
  `ConfirmReservation` flips Pending → Confirmed without touching the ledger.
  `ReleaseReservation` locks the reservation row, then lets `AdjustStock` lock
  the stock item, adds the units back (`+N Released`) and marks it Released.
- **TTL.** Omitting `reservedUntil` applies `inventory.reservation_ttl` (package
  default 30, both shops 90). `inventory:release-expired` (every 5 min) releases
  any Pending reservation past it, whatever it references; its query is
  `whereNotNull('reserved_until')`, so a reservation without a TTL is invisible
  to it. There is no way today to ask `ReserveStock` for a reservation without
  expiry except setting the config to `null` for everyone.
- `inventory:reconcile` (daily) reports, as errors, every Pending reservation
  with no TTL and every overdue one, exits non-zero, and dispatches
  `InventoryDriftDetected`. No consumer listens to that event; it reaches the log.
- **Admin orders.** The package `CreateOrder` page is a plain `CreateRecord`;
  bianka mounts it, IOW mounts a copy of its own (below). Lines are a
  relationship repeater over `HasOrders` models — in both shops that includes
  `Bundle`. Δ The line's item select is optional: a line may be a description
  and a price with no item. Status is chosen at creation (default Pending) and
  the field is disabled after. Lines stay editable until `Order::isFrozen()`
  (a settled payment); the `OrderLine` model guard enforces the same. An order
  can be deleted when it is Pending with no payment row (`isDeletable()`), so a
  checkout order, which always has one, never can. The refund actions are hidden
  for a manual payment.
- Δ **Cancel exists.** Draft 1 said there was no cancel action; there is. The
  orders table's *Update status* action offers Cancelled from Pending and from
  Confirmed (status is not a frozen field), and the package's own
  `OrderStatusChanged` listener, `SyncInventoryOnOrderStatusChange`, releases
  every Pending or Confirmed reservation of an order on its way to Cancelled and
  confirms the Pending ones on Pending → Confirmed. It is the second actor on
  reservations besides the Inventory actions, and today it already does real
  work for checkout orders an admin cancels.
- **Mark paid** (`RecordManualPayment`) dispatches `PaymentSucceeded`; each
  consumer's `HandlePaymentSucceeded` calls `ConfirmOrder`, which confirms the
  order's Pending reservations — or refuses with `StockUnavailable` if any was
  Released (`Order::hasReleasedStock()`, F14). Both consumers treat
  `AlreadyConfirmed` as confirmed too and send the confirmation mail.
  Δ The Mark paid modal's description is a static string.
- `commerce:expire-orders` already leaves orders with no payment row alone
  (`whereHas('payments')`).
- **Bundles have no stock.** Each consumer's checkout `ReserveItems` step expands
  a bundle line into its stock-tracking components itself.
- Δ **Checkout runs in one transaction.** Both consumers' chains call
  `wrapInTransaction()`, so every stock row a cart touches stays locked until
  the chain commits, taken in cart insertion order. The order and its lines are
  written two steps before `ReserveItems`; nothing writes order lines after
  that — cancel and expiry change status only, and the refund path never
  touches lines.
- **Transactions in the admin.** bianka's panel sets `->databaseTransactions()`;
  IOW's does not. A page can force it with
  `protected ?bool $hasDatabaseTransactions = true`. Δ Filament 5.9's
  `CreateRecord::create()` opens the transaction before validation, saves the
  repeater's relationship after the record and before `afterCreate()`, rolls
  back on any throwable and rethrows it into Livewire's error dialog; only a
  `Halt` ends the request quietly. Filament's `DeleteAction` has transactions
  off unless the action or the panel turns them on.
- Δ **Events fire after each action's own transaction.** `ReserveStock`,
  `ReleaseReservation` and `AdjustStock` dispatch after their `DB::transaction`
  closure returns, and the Logging domain's `LogDispatcher` says subscribers
  deliberately run after commit. Nested inside an outer transaction the inner
  ones become savepoints and the dispatches land inside the outer one.
- Only `InventoryLogSubscriber` listens to the stock events, in the package and
  in both consumers (re-grepped).
- Δ3 **Isolation and locks.** Neither consumer sets `isolation_level`, so MySQL's
  default REPEATABLE READ applies: a transaction's first consistent read fixes
  its snapshot, only `FOR UPDATE` reads see the latest committed row, and a
  `FOR UPDATE` range scan on a secondary index takes next-key locks — each
  matched entry, the gap before it, and the gap after the last.
  `ReleaseReservation`'s lock is a primary-key equality, a record lock with no
  gap. `stock_reservations` is indexed on `stock_item_id`,
  `(reference_type, reference_id)` and `(status, reserved_until)`; which index a
  scan of a hold's rows uses is the optimizer's choice, so what it gap-locks is
  data-dependent. bianka's panel transaction also wraps every Filament action
  (`Panel::databaseTransactions()`); IOW's actions have none unless flagged.
- Δ **IOW's order pages.** IOW subclasses the package `OrderResource` only to
  pin EUR (May 2026, before `currencyOptions()` read `currency.enabled` in
  v0.72.0). Because the package pages are final and name the package resource,
  IOW copies three page classes that own no behaviour; its `EditOrder` borrows
  the package page's static actions. IOW has no `config/currency.php`, so
  without the subclass it would offer every enum currency.

## 3. Decisions (settled 2026-11-04)

- **D1** Admin-created orders take stock. Reverses 2026-10-27.
- **D2** Each admin order chooses, at creation: **take from stock** (default),
  **take from a hold**, or **don't take stock** (today's behaviour — for recording
  an order whose stock was already corrected by hand).
- **D3** A hold is named, made by an admin, holds products (not bundles — see
  §8 Q1), **never expires**, and ends only by its Release button. A hold open
  past its day is flagged (§4.5).
- **D4** The admin picks the hold; nothing is decided by a rule like "oldest
  first", because a rule could quietly take the piece kept for Ana for a market
  sale. One hold per order. If exactly one hold qualifies it is preselected,
  visibly; if none does, "take from a hold" isn't offered.
- **D5** A line the hold can't cover takes what the hold has and the rest from
  main stock, and **says so on the line before saving** ("1 from Markt 15 Nov,
  1 from stock"); no confirmation tick. Boosting the hold first would end at the
  same numbers with more steps. It refuses only when main stock can't cover the
  rest either — then the record is wrong and stock is corrected first.
  Splitting into two orders was rejected (two order numbers, two Mark paid, two
  confirmation mails, two sales in the books).
- **D6** Once an order holds stock its lines are locked. To change them, delete
  the (unpaid) order and recreate it.
- **D7** A **Stock holds** admin page: list (name, date, products, units, age,
  stale badge, Release), create/edit, filter by product. The product's
  stock section shows "Held: 3 (Markt 15 Nov: 2, Kept for Ana: 1)" with links.
  No separate held-stock-per-product page.
- **D8** Holds use the permission that already governs stock — no new flag (a
  separate one would confuse admins). IOW: stock lives on the product form, so
  `can_manage_products`. bianka has no per-area flags: admin-only, like the rest.
- **D9** Customer self-holds ("hold this for 24 hours") and unpaid pre-release
  holds fit this mechanism (a hold referencing a customer, with a TTL; checkout
  consuming it through the same action) but are **not built**. The one thing
  this brief does for them: moving units from a hold into an order is a package
  action, not form code (§4.2).

Δ From critique pass 1:

- **D10** Units an order took from a hold **go back to that hold** when the
  order is deleted or cancelled, if the hold is still open; otherwise to main
  stock. The reservation the move creates records its hold
  (`stock_reservations.stock_hold_id`). Without this, D6's correction path
  (delete, recreate) puts a market piece on sale online for the gap and the
  recreate finds the hold short — the double sale the mechanism exists to stop.
- **D11** The default stays **take from stock**, and no rule ever picks a hold.
  Recording a market sale from main stock when it covers the line is harmless:
  the website shows one fewer than it could until the hold is released, the
  hold overstates the stall, and the end numbers are the same. When main stock
  is below the line quantity and open holds hold the product, the line's error
  names them ("0 in stock; 1 held in Markt 15 Nov — choose *take from a hold*").
  The hint keys on main stock being short, not on a refusal, because a
  backorderable product is never refused.
- **D12** IOW drops its `OrderResource` subclass and its three order pages, adds
  `config/currency.php` with EUR enabled (as bianka has), and registers the
  package `OrderResource`. The package pages stay final; the stock step lives in
  the package `CreateOrder` page and reaches both shops. Redundant code goes,
  and the two shops move back towards each other.
- **D13** Lines lock whenever an order holds stock, **checkout orders included**
  (their lines are editable today until paid, for the hour before expiry cancels
  them; editing them under live reservations desyncs the two). The refusal has
  its own message; it is not "frozen".
- **D14** Permission follows the surface: anything done through an order needs
  the orders permission, the Stock holds page needs the products permission (D8
  unchanged). An order admin draining a hold they cannot see does not arise: at
  IOW the orders flag implies the products flag by convention (the reverse can
  happen — products without orders). Not enforced in code.
- **D15** A stale hold or admin order is a **warning, never drift**: it does
  not dirty the reconcile report, fail the nightly command, or fire
  `InventoryDriftDetected`. Awareness is on the panel, not in a log: the stale
  badge on the Stock holds page and the same badge on the orders list.

## 4. Design

### 4.1 The hold

- New model `Inventory\Models\StockHold`: `name` (required), `held_for` date
  (optional — a market day), `notes`, `released_at`, timestamps. Morph alias
  `stock_hold`.
- **A hold has no lines table.** Its lines are the Pending reservations that
  reference it, and the held quantity of a product is the sum of those — derived
  at read time, never stored (no counter to drift, so no tripwire to add).
- Holds reserve with `source: 'dashboard'`, which all three `inventory.sources`
  configs already list, so consumers need no config change for it.
- Δ Creating a hold reserves with `rejectOversell: true` whatever
  `allowsBackorder()` says: a hold is pieces you pack, and bianka's made-to-order
  products sit at negative stock on purpose.
- Δ Editing a hold is **"set each product to N under the lock"**: the save locks
  the hold's Pending reservations for that product, reads the quantity actually
  held now (a concurrent order may have drained it since the page loaded), and
  reserves or releases the difference. An increase is one reserve; a reduction
  is release + re-reserve, three movements. The form never computes a delta.
  Every change is a ledger movement with a description naming the hold.
- Δ3 **The hold row is the first lock.** Every hold actor — create, edit,
  release, the move (§4.2), the move-back (§4.4) — takes the `stock_holds` row
  `FOR UPDATE` before anything else and reads `released_at` from that locked
  row. A consistent read of it inside a transaction is a snapshot and can say
  "open" after Release has committed: without this lock a move-back could land
  a Pending reservation on a released hold, stranding the units (not for sale,
  on no order, no button to free them). The same lock serialises the editor's
  zero-rows case, where two saves on a product the hold does not yet list would
  each read zero and each insert.
- Δ3 Both `StockHold` pages force `$hasDatabaseTransactions = true` and treat
  `InsufficientStockException` as the order page does (§4.3): notification,
  then `Halt` with rollback. Without it, on IOW, a refused second product leaves
  a hold with only the first.
- **Release** (`ReleaseHold`, a `Release*` modifier per `docs/verb-families.md`)
  releases whatever is still Pending for the hold and stamps `released_at`, Δ in
  one transaction, Δ3 hold row first, then its stock items sorted, then the
  rows (§4.2's order), so a concurrent move cannot interleave between rows. A
  released hold is read-only. Δ3 Releasing again is an idempotent sweep, not a
  no-op on the flag: the button stays available while any Pending row still
  references the hold, which is also the repair for a row that reached a
  released hold by a path this brief did not foresee (§4.5 names it an error).
- Δ **Age** is measured from `held_for` when set, else from `created_at` (§4.5).

### 4.2 Moving units from a hold into an order — `ReserveFromHold`

One package action, the only way units leave a hold for an order:

1. Δ3 **One lock order, for every reservation actor**: (1) the hold row, when a
   hold is involved; (2) every stock item the actor will touch, all of them up
   front, sorted by morph class and key as `AdjustStock::orderForLocking()`
   does; (3) reservation rows; (4) inserts. Draft 2 said "reservation row, then
   stock item — the order `ReleaseReservation` locks in, so the two cannot
   deadlock". That was wrong: a `FOR UPDATE` scan of a hold's rows takes gap
   locks (§2); a concurrent checkout already holding the stock item then waits
   on that gap to insert its own reservation, while the scan waits on the stock
   item — a deadlock on exactly the last-piece race the mechanism exists for.
   InnoDB kills one; no corruption, but the loser is a failed checkout or an
   error dialog. Holding every stock item first closes it: anyone who can insert
   into, or release from, a stock item's range must hold that stock item. For
   that to be true of releasers too, `ReleaseReservation` adopts the same order
   (it knows `stock_item_id` before it locks the row); release-expired, the
   cancel listener, refund restock, `ReleaseHold` and `ReleaseOrderStock` then
   all stack the same way, and the optimizer's index choice stops mattering.
   `ConfirmReservation` never touches a stock item and is unchanged. Any actor
   touching more than one stock item inside one transaction — the cancel
   listener, `ReleaseHold`, `ReleaseOrderStock`, refund restock, this action —
   locks them all first, sorted, never in reservation-row order (a pre-existing
   cancel-vs-checkout deadlock, which the brief would otherwise have made
   systematic).
2. Lock the hold's Pending reservations for the stockable (`lockForUpdate`),
   Δ3 and take *held* from that result set, never from a separate aggregate: a
   consistent read inside the page transaction is a snapshot from before the
   wait and would still show rows another move has since released. Release
   them, reserve `min(needed, held)` for the order, and re-reserve the remainder
   for the hold. `stock_level` ends where it started; the ledger shows release +
   two reserves, each described "Moved from hold {name} to order {n}". Δ Both
   re-reserves pass `rejectOversell: false`: they move units, they claim none,
   and on a product at negative stock the default would throw. Δ3 Both pass
   `expires: false` too, or the remainder goes back on sale 90 minutes later.
   Δ3 The action asserts every `ReleaseReservation` it issued returned non-null.
   The reservation created for the order carries `stock_hold_id` (D10).
3. Return the shortfall to the caller, which reserves it from main stock (D5).
   Δ3 The caller passes in the split the admin was shown; if the split under
   the lock differs — the hold was drained or topped up between page load and
   save — the save is refused (`Halt` with rollback, "the hold changed; review
   the lines") rather than committing a split the admin never saw. Load-to-save
   is the wide window; it re-validates.

Δ Everything commits or rolls back together **because the actions nest**: each
dispatches its events after its own `DB::transaction`, which inside this one is
a savepoint, so the log subscriber's rows roll back with it. The guarantee is
the outer transaction's, not the actions'. No listener reacts to the transient
`+N`, and no reader outside the transaction sees it.

Δ3 On IOW, a hold on one locale sibling of a shared-inventory group must be
found for an order on another sibling: the picker (§4.3) and this action match
reservations across the group's stock items — `AdjustStock::resolveTargets()`
is the seam. Otherwise a DE order neither finds the EN hold nor gets the D11
hint, and its main-stock reserve is refused on the shared level with no message
naming the hold.

### 4.3 Creating an admin order

- The lines tab gets the stock choice (D2) above the repeater, and the hold
  picker when "from a hold" is chosen. Per D5, the picker lists open holds that
  hold **at least one** of the order's products; each line shows its split,
  including "2 from stock" for a product the hold doesn't have. Δ A line with no
  item takes no stock and the picker ignores it. For a backorderable product at
  zero stock the split reads "1 backordered", not "1 from stock".
- Δ **The D11 hint.** In "take from stock" mode, a line whose quantity exceeds
  main stock while open holds hold the product gets the error naming those
  holds, so the admin switches mode rather than guessing why it refused.
- Availability is checked in form validation (field errors on the line), then
  again under the lock when saving. A lost race — the online shop sold the last
  piece between validation and save — throws `InsufficientStockException`.
  Δ `afterCreate()` catches it, shows it as a danger notification, and throws
  Filament's `Halt` with rollback, so the request ends quietly with **nothing
  created** instead of in Livewire's error dialog. Δ3 A deadlock or lock-wait
  `QueryException` (SQLSTATE 40001; errors 1213 and 1205) is caught the same
  way, with "try again": nothing created, no error dialog.
- The package's `CreateOrder` forces `$hasDatabaseTransactions = true`, so the
  order, its lines and its reservations commit together in both shops,
  whatever the panel setting. Δ IOW reaches this page after D12; nothing is
  duplicated there.
- The stock step runs in `afterCreate()` (lines are saved by then) through one
  Commerce action (`ReserveOrderStock`, §8 Q3) that walks the lines: a line
  with no item is skipped, a `tracksStock() === false` stockable is skipped, a
  bundle line expands to components (§8 Q1), Δ the stockables are sorted and
  Δ3 every stock item is locked first (§4.2),
  and main-stock reservations use `rejectOversell: ! allowsBackorder()`, as
  checkout does.
- **Admin-order reservations have no expiry.** A bank transfer can take days;
  with the 90-minute TTL the piece would go back on sale and Mark paid would then
  hit `StockUnavailable`. `ReserveStock` gains an additive `expires: bool = true`
  parameter; holds and admin orders pass `false`. Δ An explicit `reservedUntil`
  with `expires: false` is a programming error and throws.
- Created as Pending: Mark paid confirms the reservations through the existing
  `ConfirmOrder` path, Δ and the table's *Update status → Confirmed* confirms
  them through the status listener; both exist today, nothing changes there.
  Created in another status: §8 Q2.
- The Edit page shows what the order holds, read-only: "1 × Bowl (from Markt 15
  Nov), 1 × Bowl (from stock)". Δ The choice made at creation is not stored: a
  "don't take stock" order is simply one with no reservations, and since a
  failed stock step rolls the whole order back, an order that exists without
  reservations chose that (or predates this brief).

### 4.4 Guards

- **Lines lock (D6, D13)** on the model, not only in the form: the `OrderLine`
  guard refuses save/delete when the order is frozen **or holds stock** (a
  Pending or Confirmed reservation referencing it), Δ with its own exception and
  message (`OrderHoldsStockException`, §8 Q3) — "frozen" would be wrong for an
  unpaid order. The repeater is disabled to match, with helper text saying why
  and what to do. Order deletion is unaffected: `order_lines` cascade at the
  database, so the guard never fires for it.
- Δ **Releasing an order's stock is one Commerce action**, `ReleaseOrderStock`
  (§8 Q3), that both the cancel path and the delete path call: a reservation
  whose `stock_hold_id` names an open hold is moved back to it (the §4.2 dance
  in reverse, same locks, same order); any other reservation is released (D10).
  The rule is *open hold, same product*: a hold edited to zero of that product
  after the move still gets the units back while it is open, and shows them.
  `SyncInventoryOnOrderStatusChange` calls it on the way to Cancelled instead
  of `ReleaseReservation` per row — identical for checkout orders, which carry
  no hold id. Δ3 It takes the hold row first and locks all the order's stock
  items sorted (§4.2), its move-back re-reserves with `expires: false`, and it
  takes the order number from the `$order` it is handed: in the `deleted` hook
  the row is gone, `$reservation->reference` resolves to null, and the release
  movement would otherwise carry no reference.
- **Deleting an unpaid admin order releases its stock** through the same action,
  in an `Order::deleted` hook (`deleted`, not `deleting`: a later `deleting`
  listener may still veto, and stock released for an order that survives would
  make its Mark paid fail). Δ The package `EditOrder` page's Delete action gains
  `->databaseTransaction()`, so on IOW, whose panel has none, the row and the
  release commit together. Δ3 Draft 2 had the failure case backwards: with that
  transaction a release that fails rolls the delete back and shows the error —
  nothing is orphaned, and the hook propagates rather than swallows. The orphan
  case is the non-Filament path (tinker, a test, a future bulk action), where
  the DELETE autocommits before the hook runs; `isDeletable()` is the only guard
  there, and §4.5 reports what it leaves.

### 4.5 Reconcile and detection

`ReconcileStock` changes:

- A Pending, no-TTL reservation is **not** an error when it references an open
  hold, or an existing order with no payment row (the package's existing
  definition of an admin order, from `ExpireAbandonedOrders`).
- Δ **Stale is a warning, not drift (D15).** A hold is stale
  `inventory.hold_warn_after_days` (default 7) past its `held_for`, or past its
  creation when it has none — so "Markt 15 Nov" made on 1 Nov is not flagged a
  week before the market. An admin order's reservations are stale that many
  days past creation. `StockReconciliationReport` gains `staleHoldIds` and
  `staleOrderIds`; `isClean()` ignores them, the command prints them as
  warnings and still exits 0, and `InventoryDriftDetected` does not fire for
  them. No consumer constructs the report, so the constructor change is safe.
- Everything else with no TTL stays an error — including a reservation whose
  order was deleted without releasing (§4.4).
- Δ3 A Pending reservation referencing a **released** hold is an error too; the
  hold-row lock (§4.1) is meant to make it impossible, and `ReleaseHold`'s sweep
  is the repair.
- Δ3 A refused admin order leaves no audit row: `StockReservationFailed` is
  logged inside the transaction the `Halt` rolls back, as for a refused checkout.
  Only the notification remains; a one-liner if "how often was a market sale
  refused" ever matters.

Δ The visible surface is on the panel: the stale badge on the Stock holds page
(D7), and the same badge on the orders list for a Pending order that has held
stock past the threshold — a bank transfer that never came shows up where
orders are looked at. The badge is computed page-wide, a subquery in
`modifyQueryUsing` for the oldest Pending reservation referencing each order,
as the refund column's aggregate is — never a query per row (2026-09-07 audit
S1). The reconcile report reaches the log, as today.

### 4.6 Admin surfaces

- `StockHoldResource` in the package (Inventory). Consumers add it to their
  panel's `->resources([...])`, as IOW does for `PurchaseOrderResource`.
- `InventorySchema::stockSection()` gains the held line (D7).
- Δ The Mark paid modal (`orders.actions.mark_paid_modal`, en + es) becomes a
  closure on the order: one holding stock is told its held stock is confirmed;
  one holding none keeps today's "Stock is not adjusted …" sentence, which stays
  true for a "don't take stock" order and for every order that predates this.
  Spanish text is a draft for Bianka.
- Δ The orders list gets the stale badge (§4.5).

## 5. Periphery map — what fires differently after this brief

| Actor | Before | After |
|---|---|---|
| `ReserveStock` | TTL always applied when omitted | `expires: false` gives a reservation with no TTL; default unchanged |
| `ReleaseReservation` / `ReleaseHold` / `ReserveFromHold` / Δ `ReleaseOrderStock` | — | new movements described per hold; `ReservationReleased` / `StockReleased` / `ReservationCreated` fire during a move, and during a move back |
| Δ3 `ReleaseReservation` lock order | reservation row, then stock item | stock item first, then the row — every releaser (release-expired, cancel listener, refund restock, hold actors) stacks like every reserver |
| Δ3 `StockHold` pages | — | forced page transaction; `Halt` on a refused product |
| `InventoryLogSubscriber` | logs checkout reservations | also logs hold and admin-order movements (more rows per market sale) |
| `inventory:release-expired` | releases every overdue Pending | unchanged; never sees holds or admin orders (no TTL) |
| `inventory:reconcile` / `InventoryDriftDetected` | no-TTL Pending = error | holds and admin orders exempt; Δ stale ones listed as warnings, exit 0, no event; orphans still errors |
| Δ `StockReconciliationReport` | three lists | five lists; `isClean()` means the same |
| `commerce:expire-orders` | skips payment-less orders | unchanged |
| `ConfirmOrder` via consumers' `HandlePaymentSucceeded` | confirms zero reservations on an admin order | confirms the admin order's reservations |
| Δ `SyncInventoryOnOrderStatusChange` | releases a checkout order's reservations on Cancelled, confirms on Confirmed | the same for admin orders, through `ReleaseOrderStock`; hold-sourced units return to an open hold |
| Δ Orders table *Update status* action | changes an admin order's status only | also moves its stock, through the listener above |
| `Order` model hooks | `deleting` refuses frozen | + `deleted` calls `ReleaseOrderStock` |
| `OrderLine` model guard | refuses when frozen | refuses when frozen or holding stock — Δ checkout orders included, own message |
| Δ Package `EditOrder` Delete action | no transaction | runs in one |
| Storefront availability, cart gate | read `stock_level` | unchanged — held units are simply not for sale |
| IOW MCP stock tools (`get_stock_level`, `list_stock_levels`, `low_stock_report`, `adjust_stock`) | read/adjust `stock_level` | unchanged; held units read as lower stock, which is true |
| in-other-books | reads orders through the read API | unaffected (no stock read) |
| Δ IOW `currency.enabled` readers (D12) | no config: every enum currency offered in the pricing schema's currency select, the supplier form, the Storefront provider's enabled list, and the order form via the subclass | EUR only everywhere — the state bianka already runs |

**New public surface** (for the three `periphery.md` files): `StockHold`,
`StockHoldResource`, `ReserveFromHold`, `ReleaseHold`, `ReserveOrderStock`,
Δ `ReleaseOrderStock`, `ReserveStock(expires:)`, Δ
`stock_reservations.stock_hold_id`, `OrderHoldsStockException`, the two report
lists, `inventory.hold_warn_after_days`, the orders-list badge, and the bundle
contract if Q1 goes ahead.

## 6. Consumer impact on the bump (both shops)

- Migration: `stock_holds`, Δ plus a nullable `stock_hold_id` FK on
  `stock_reservations` (explicit FK names; recovery-aware `up()` — the MySQL
  DDL rule). Run the MySQL CI leg before tagging.
- `config/inventory.php`: add `hold_warn_after_days` — `PackageConfigParityTest`
  in both consumers fails until it's there, which is the intended tripwire.
- Register `StockHoldResource` in the panel. IOW: a `StockHoldPolicy extends
  AbstractBooleanPolicy` returning `canManageProducts()`, mapped in
  `registerAuthorization()`, plus rows in `PermissionPolicyTest` and
  `ResourceAccessTest`. bianka: map it to `AdminOnlyPolicy`.
- Δ **IOW, its own PR, first** (D12; it needs nothing from the package release):
  add `config/currency.php` carrying every package key (`enabled` = `['EUR']`,
  `display_locale`, `default`) or `PackageConfigParityTest` fails; delete
  `app/Filament/Resources/OrderResource.php` and its three pages (the panel
  discovers `app/Filament/Resources`, so the subclass must go, not merely be
  unlisted); register the package `OrderResource` in `AdminPanelProvider`;
  point `tests/Feature/Filament/Resources/OrderResource/EditOrderTest.php` at
  the package page; check that the pricing schema, the supplier form and the
  Storefront provider now offer EUR only, which is the intended state. IOW's
  edit page then also shows the package page's `afterFill()` notices (frozen
  order; refunded but not cancelled), which its copy had skipped on purpose —
  wanted, but new.
- `Bundle` implements the bundle contract, and (if Q1 says so) each
  `ReserveItems` step switches to it, Δ sorted as §4.2.
- `docs/periphery.md` in all three repos.
- Δ Docs that say admin orders hold no stock: the `ExpireAbandonedOrders`
  docblock and its `candidateIds()` comment, bianka's `TODO.md` line (the
  2026-10-27 lean), and the Mark paid modal strings.
- Δ Test maintenance: a test that builds lines after reservations through the
  factories hits the D13 guard.

## 7. Tests (each new guard checked red on the old code first)

- `ReserveFromHold`: full, partial (hold 1, line 2 → 1 + 1 from stock),
  refused (hold 1, line 2, stock 0 → nothing written); `stock_level` and ledger
  sum unchanged by a move; Δ a move on a product at negative stock does not
  throw; Δ the order's reservation records the hold.
- Δ Hold create on a product at negative stock is refused.
- Δ Hold edit "set to N": a drain between page load and save still lands on N.
- Δ Return to hold: delete and cancel of an order that took from an open hold
  put the units back in the hold; from a released hold they go to main stock; a
  checkout order's cancel is unchanged.
- Δ Lock order: the Commerce action's stockable sequence is sorted, bundles
  expanded first (a unit test on the sort).
- Admin order from stock: reservation has no TTL; `release-expired` leaves it;
  Mark paid confirms it; *Update status → Confirmed* confirms it; a lost race
  creates nothing — Δ no order, no lines, no reservations, and a notification.
- "Don't take stock": no reservations; today's behaviour.
- Δ A line with no item takes no stock; a backordered split says so.
- Δ The D11 hint appears when main stock is short and a hold holds the product,
  and not when main stock covers.
- Lines locked when holding stock (model and form); unlocked with no stock;
  Δ a Pending checkout order's lines are refused with the holds-stock message
  and editable again after cancel.
- Deleting an unpaid admin order releases its stock, Δ in one transaction.
- Reconcile: hold and admin-order reservations aren't errors; Δ stale ones are
  listed, measured from `held_for`, `isClean()` stays true and the command
  exits 0; an orphan still errors.
- Release: returns everything; twice is a no-op; a released hold can't be picked.
- Bundle line takes its components from the hold.
- Δ Mark paid modal copy branches on whether the order holds stock.
- Δ The orders-list badge shows for a stale admin order only.
- IOW: a products-only admin manages holds; a content-only admin can't.
- Δ Concurrency: "two concurrent moves serialise" needs two DB connections in
  one process and a lock-wait timeout, which nothing in the suite does today.
  Replaced by the lock order asserted on the query log — Δ3 hold row, then the
  stock items in sorted order, then the reservation rows, and no non-locking
  read of `stock_reservations` between the lock and the reserve — for the move,
  the move-back, `ReleaseHold` and `ReleaseReservation`; and Δ3 "the hold
  changed between load and save: the save is refused, nothing created". A
  two-connection harness, if ever wanted, is its own small piece of work (§9).
- Δ3 After a partial move, neither the order's nor the remainder's reservation
  has a TTL; `release-expired` leaves both.
- Δ3 Saving notes on an order that holds stock succeeds through the Livewire
  edit page (the disabled repeater is skipped, not refused) — no package or
  consumer test drives an edit-page save of a frozen or holding order today.
- Δ3 A hold create whose second product is refused leaves no hold and no
  reservation, on a page without panel transactions.
- Δ3 A deadlock `QueryException` in the order's stock step ends in a "try
  again" notification with nothing created.
- Δ3 Reconcile lists a Pending reservation on a released hold as an error;
  `ReleaseHold` on that hold sweeps it.
- Δ3 IOW: a hold on the EN edition is found and used by an order for the DE
  edition of a shared-inventory group.

## 8. Open questions (leans in brackets)

- **Q1 — Bundles.** A bundle has no stock; each consumer expands it at checkout.
  The admin-order path lives in the package, so it needs a seam: a
  `HasStockComponents` contract (`stockComponents(): list<StockComponent>`) that
  both shops' `Bundle` implements. Switch both checkout `ReserveItems` steps to
  the same contract in the same bump, so there is one expansion rule rather than
  three? [Yes — same mechanic, same classes. Holds themselves stay products-only;
  holding five bundles means holding their components. Δ The switch also brings
  the §4.2 sort to checkout.]
- **Q2 — Orders created past Pending.** Status is chosen at creation. [Created
  Confirmed (or later): take the stock and confirm the reservations in the same
  transaction. Created Cancelled: take none. The alternative — only Pending at
  creation — is simpler but changes what admins can do today.]
- **Q3 — Names.** `StockHold`, `ReleaseHold`, `ReserveFromHold` (a `Reserve*`
  modifier — new for Inventory, so `docs/verb-families.md` gains the line),
  `ReserveOrderStock` and Δ `ReleaseOrderStock` for the order's stock step and
  its reverse, Δ `OrderHoldsStockException`. [As listed.]
- **Q4 — Picker scope.** D4 said the picker lists holds that can cover the
  order; D5 relaxed coverage, so §4.3 lists holds holding at least one of the
  order's products. [Confirm.]
- **Q5 — Stale threshold.** One key for holds and admin orders. [7 days,
  Δ measured as §4.5.]

## 9. Out of scope (flagged, not built)

- Customer self-holds and unpaid pre-release holds (D9).
- Δ A paid manual order has no refund path: its refund actions are hidden, and
  recording a bank refund is not built. It *can* be cancelled from the orders
  table, which releases its stock through the status listener — but that leaves
  a Succeeded payment on a Cancelled order, which in-other-books reads. Until a
  bank refund exists, a returned market piece goes back through a manual stock
  adjustment, as today.
- A held count in the IOW MCP stock tools.
- Moving the hold picker to each line (only if mixed-hold orders turn out to be
  common; same action underneath).
- Δ Enforcing "orders implies products" on IOW's user form (D14): convention only.
- Δ A two-connection concurrency harness for the test suite (§7).

## 10. Cost and sequencing

Δ About 4 days (draft 1 said 2; the hold editor, the return-to-hold path, the
reconcile report, the Halt handling, the bundle contract across three repos and
the policy rows were under-counted; Δ3 add half a day for `ReleaseReservation`'s
lock-order change and its tests). In order:

1. IOW's D12 cleanup, its own half-day PR, first — no package dependency, and it
   is what lets the package pages reach IOW.
2. One package PR, a release.
3. One PR per consumer (each deploys once).

Can share v0.74.0 with R4 and Complexity Release 2 if those go ahead; otherwise
its own minor. Jelte pushes the tag.

## 11. Critique changelog

**Draft 1 → draft 2 (critique pass 1, 2026-11-04).** Every §2 fact was re-read
in code; the design was attacked on concurrency and partial failure first, as
draft 1 asked. Found and folded in:

- IOW never reached the package `CreateOrder` page (its own copy) → D12.
- Cancel exists and already releases stock through the status listener; draft
  1 said neither → §2, §4.4, §5, §9.
- Delete and cancel sent hold-sourced units to main stock → D10, `stock_hold_id`,
  `ReleaseOrderStock`.
- The §4.2 lock argument covered one stockable, not an order; checkout holds
  every row of a cart in one transaction → the sort in §4.2.
- Moves re-checked oversell and would throw on negative stock; holds took the
  backorder flag → §4.1, §4.2.
- The hold editor had no contract under concurrency → "set to N under the lock".
- Staleness ignored `held_for` and would have failed the nightly command →
  D15, §4.5, the orders-list badge.
- "Events included" held only by nesting → stated as such.
- The lost race ended in Livewire's error dialog → `Halt`.
- D6's guard also locks in-flight checkout orders → D13, own message.
- The Mark paid modal string was static → closure.
- Delete on IOW ran outside a transaction → `->databaseTransaction()`.
- Item-less lines, backorder wording, `expires:` precedence, the second confirm
  path, the concurrency test's feasibility, docs that say admin orders hold no
  stock, and the cost → §4.3, §6, §7, §10.
- Question raised and dropped: defaulting the stock choice to a lone matching
  hold. Jelte's point: main stock is harmless when it covers; what matters is a
  refusal that names the holds → D11.
- Question raised and dropped: the orders/products permission asymmetry. At IOW
  orders implies products by convention → D14.

**Draft 2 amendment (self re-read, 2026-11-04):** the orders-list badge is a
page-wide subquery, not a query per row; D10 applies to a hold that no longer
lists the product; D12 brings the package edit page's `afterFill()` notices to
IOW.

**Draft 2 → draft 3 (pathology review of §4.2 and §4.4 by a fresh reviewer, 2026-11-04).**
Ten findings, all folded in:

- The move-back could land a Pending reservation on a released hold (no actor
  locked the hold row; a consistent read of `released_at` is a snapshot) → the
  hold row is every hold actor's first lock; `ReleaseHold` is an idempotent
  sweep; reconcile names the case.
- "Same order as `ReleaseReservation`, so no deadlock" ignored next-key locks: a
  hold scan's gap lock vs a checkout's insert, each waiting on the other → one
  lock order for every actor, stock items first and sorted, `ReleaseReservation`
  included; a deadlock in the stock step ends in "try again".
- The sort covered the reserve side only; multi-row releasers locked stock items
  in row order (pre-existing cancel-vs-checkout, made systematic) → same rule.
- "Held now" read outside the `FOR UPDATE` result set is a pre-wait snapshot →
  taken from the locked rows; every release asserted non-null.
- Hold pages had no forced transaction and no `Halt` → they do.
- The split shown is not the split saved when the hold moved in between → the
  shown split is passed in and a difference refuses the save.
- `expires: false` unstated for the move's re-reserves and the move-back → stated.
- The delete hook's failure sentence was backwards; `$reservation->reference` is
  null once the row is gone → corrected; order number from the in-memory order.
- Shared-inventory siblings on IOW: a hold on one edition invisible to an order
  on another → matched across the group.
- The refusal's audit row rolls back with the order → noted.

Verified as holding by that review: D13's writer list (every Eloquent writer of
`order_lines` runs before `ReserveItems` or on frozen orders; Filament skips a
disabled relationship repeater on save), the `CreateRecord::create()` ordering
and `Halt` semantics, events landing inside the outer transaction, move vs
`ConfirmOrder` (an uncommitted order cannot be found), the cancel path's lock
order against `ConfirmOrder`, the expiry and expire-orders filters, Mark paid's
synchronous confirm and after-commit mail, `isDeletable()` / `isFrozen()` /
`hasReleasedStock()`, and both chains' single transaction.

## 12. Build notes (2026-11-04)

Where the brief was silent or a convention pulled another way, the build chose
as below. Each is small and reversible; none reopens a D or a §8 lean.

- **Reconcile seam — `HasOpenReservations`, and `staleReferences` instead of
  `staleOrderIds`.** §4.5 has Inventory's ReconcileStock recognise "an existing
  order with no payment row". Inventory knowing what an order is would make it
  depend on Commerce (a cycle; CLAUDE.md's dependency graph). So Inventory asks
  the reference: `HasOpenReservations::keepsReservationsOpen()` /
  `reservationsOpenSince()`, implemented by `StockHold` and `Order`. The report
  carries `staleHoldIds` (Inventory's own) and `staleReferences`
  (`{type, id}`, e.g. `order#12`) rather than `staleOrderIds`. Behaviour is the
  brief's; only the name and the seam differ.
- **`AdjustHold`** names the hold editor's "set each product to N under the
  lock" (§4.1 named no action). `Adjust` because it computes and applies a
  signed delta; its target parameter is `$to`. Added to `verb-families.md`.
- **`ReleaseReservation(reference:)`**, additive: §4.4's "takes the order number
  from the `$order`" needs the release *movement* to carry the deleted order's
  reference too, which only the caller still has.
- **Exceptions:** `StockHoldReleasedException` (Inventory; a move on a released
  hold, read under the hold-row lock) and `StockHoldChangedException`
  (Commerce; §4.2 step 3's refusal).
- **Support classes:** `Inventory\Support\StockLocks` is the lock order (hold
  row; every stock item, sorted; extracted from AdjustStock, which uses it);
  `Commerce\Order\Support\OrderStockDemand` is the one arithmetic — lines to
  stockables, sorted, split between hold and stock — that the page shows and
  ReserveOrderStock re-runs; `Commerce\Filament\OrderStockPreview` is the
  page's read side.
- **"The split shown" (§4.2 step 3)** is a snapshot of what the picked hold
  holds, taken when it is picked or the lines change; each line's split is
  shown from it and the save compares, per stock group, what came from the
  hold against `min(demand, held as shown)`. A hold topped up beyond the lines
  saves the split shown; a drained one refuses, and the page re-snapshots so
  the next save goes through.
- **D11 hint as an error even for a backorderable product.** D11 keys the hint
  on main stock being short "not on a refusal, because a backorderable product
  is never refused" — taken to mean the error shows for it too, so it blocks a
  save that would otherwise backorder. Say if it should be a warning there.
- **Lock conflicts** are recognised with Laravel's `ConcurrencyErrorDetector`
  (40001, 1213, 1205). Inside the page's transaction a deadlock arrives as
  `DeadlockException`, not `QueryException`; the page test caught it.
- **Mark paid copy:** the holding-none sentence now says "this order holds no
  stock" instead of "an order created here holds no stock" (no longer true in
  general); en + es. Spanish uses *apartado* for a hold throughout — drafts
  for Bianka.
- **Pathology review of the build (fresh reviewer, 2026-11-04)** found, and the
  build fixed: the hold pages treated two editions of a shared-inventory group
  as two products while every hold actor reads the group as one (an unchanged
  save could put a unit back on sale) — the pages now work per stock group;
  the hold pages locked one product at a time — they now lock the hold and
  every stock item first; a removed product was zeroed even when the form never
  showed it (units that came back while the page was open) — only products the
  form loaded are zeroed; the order page re-snapshotted a changed hold inside
  the transaction it was rolling back — it now re-reads after the rollback; and
  the lock-order probe compared first locks per table only, so it could not see
  a missing up-front lock — it now names each stock item and asserts all of
  them, sorted, before the first reservation row. Noted, not changed: a
  partial-refund restock of a unit taken from a hold goes to main stock (D10
  says delete or cancel; a manual payment has no refund path anyway, §9).
- **Not done here, flagged:** CLAUDE.md says an existing registry is stripped
  when its domain gains a model. `StockHold` has no `models` key, as the rule
  asks, but the `Inventory` registry and its three keys stay: stripping them
  touches every Inventory callsite and both consumers' configs, which is its
  own change.

