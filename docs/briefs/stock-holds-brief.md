# Brief — stock holds, and admin orders that take stock

Status: **draft 1** (2026-11-04). Decisions D1–D9 were settled with Jelte on 2026-11-04;
§8 holds what is still open. Next: a critique pass on this draft (concurrency and
partial failure in §4.2 above all), then a rebrief, then build. Nothing built.

This **reverses the 2026-10-27 ruling** that admin-created orders stay stock-neutral
(`TODO.md` § Open, bianka `TODO.md`). That ruling asked for a hand procedure
(take the piece offline before a market, then record the sale); this brief
replaces the hand procedure with the mechanism.

## 1. Goal

An admin can set stock aside for a reason ("Markt 15 Nov", "Kept for Ana") so it
can't sell online, and an order created in the admin takes its stock either from
main stock, from one of those holds, or not at all. A market day then works
without hand steps: hold the pieces you pack, record each sale from the hold,
release what comes back.

## 2. How stock works today (facts the design rests on)

- One number per stockable, `stock_items.stock_level`, is what can sell. It is
  net of reservations: **a reservation already means "not for sale"**; there is
  no separate reserved pot.
- `ReserveStock` subtracts at once (a `-N Reserved` movement under the
  `AdjustStock` row lock) and creates a `Pending` `StockReservation` with a
  morph `reference` (today always an order) and a `reserved_until`.
  `ConfirmReservation` flips Pending → Confirmed without touching the ledger.
  `ReleaseReservation` adds the units back (`+N Released`) and marks it Released.
- **TTL.** Omitting `reservedUntil` applies `inventory.reservation_ttl` (package
  default 30, both shops 90). `inventory:release-expired` (every 5 min) releases
  any Pending reservation past it, whatever it references. There is no way today
  to ask `ReserveStock` for a reservation without expiry except setting the config
  to `null` for everyone.
- `inventory:reconcile` (daily) reports, as errors, every Pending reservation
  with no TTL and every overdue one, and dispatches `InventoryDriftDetected`. No
  consumer listens to that event; it reaches the log.
- **Admin orders.** `CreateOrder` is a plain `CreateRecord`. Lines are a
  relationship repeater over `HasOrders` models — in both shops that includes
  `Bundle`. Status is chosen at creation (default Pending) and disabled after.
  Lines stay editable until `Order::isFrozen()` (a settled payment); the
  `OrderLine` model guard enforces the same. An unpaid, payment-less order can be
  deleted (`isDeletable()`); there is no cancel action for it, and the refund
  actions are hidden for a manual payment.
- **Mark paid** (`RecordManualPayment`) dispatches `PaymentSucceeded`; each
  consumer's `HandlePaymentSucceeded` calls `ConfirmOrder`, which confirms the
  order's Pending reservations — or refuses with `StockUnavailable` if any was
  Released (`Order::hasReleasedStock()`, F14).
- `commerce:expire-orders` already leaves orders with no payment row alone.
- **Bundles have no stock.** Each consumer's checkout `ReserveItems` step expands
  a bundle line into its stock-tracking components itself.
- **Transactions.** bianka's panel sets `->databaseTransactions()`; IOW's does
  not. A page can force it with `protected ?bool $hasDatabaseTransactions = true`.
- Only `InventoryLogSubscriber` listens to the stock events, in the package and
  in both consumers.

## 3. Decisions (settled 2026-11-04)

- **D1** Admin-created orders take stock. Reverses 2026-10-27.
- **D2** Each admin order chooses, at creation: **take from stock** (default),
  **take from a hold**, or **don't take stock** (today's behaviour — for recording
  an order whose stock was already corrected by hand).
- **D3** A hold is named, made by an admin, holds products (not bundles — see
  §8 Q1), **never expires**, and ends only by its Release button. A hold open
  longer than 7 days is flagged (§4.5).
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
  over-7-days badge, Release), create/edit, filter by product. The product's
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
- Creating or editing a hold is reserve-more / release-some through the same
  internal path as §4.2, so every change is a ledger movement with a description
  naming the hold.
- **Release** (`ReleaseHold`, a `Release*` modifier per `docs/verb-families.md`)
  releases all the hold's Pending reservations and stamps `released_at`. A
  released hold is read-only. Releasing twice is a no-op.

### 4.2 Moving units from a hold into an order — `ReserveFromHold`

One package action, the only way units leave a hold for an order:

1. In one transaction, lock the hold's Pending reservations for the stockable
   (`lockForUpdate`), then let `AdjustStock` take the `stock_items` row lock —
   **the same order `ReleaseReservation` locks in** (reservation row, then stock
   item), so the two cannot deadlock each other.
2. Release them, reserve `min(needed, held)` for the order, and re-reserve the
   remainder for the hold. `stock_level` ends where it started; the ledger shows
   release + two reserves, each described "Moved from hold {name} to order {n}".
3. Return the shortfall to the caller, which reserves it from main stock (D5).

Everything commits or rolls back together, events included (the log subscriber
writes inside the transaction). No listener reacts to the transient `+N`, and
no reader outside the transaction sees it.

### 4.3 Creating an admin order

- The lines tab gets the stock choice (D2) above the repeater, and the hold
  picker when "from a hold" is chosen. Per D5, the picker lists open holds that
  hold **at least one** of the order's products; each line shows its split,
  including "2 from stock" for a product the hold doesn't have.
- Availability is checked in form validation (field errors on the line), then
  again under the lock when saving. A lost race — the online shop sold the last
  piece between validation and save — throws `InsufficientStockException`; the
  page shows it and **nothing is created**.
- The package's `CreateOrder` forces `$hasDatabaseTransactions = true`, so the
  order, its lines and its reservations commit together in both shops,
  whatever the panel setting.
- The stock step runs in `afterCreate()` (lines are saved by then) through one
  Commerce action (name in §8 Q3) that walks the lines: a `tracksStock() ===
  false` stockable is skipped; a bundle line expands to components (§8 Q1);
  main-stock reservations use `rejectOversell: ! allowsBackorder()`, as checkout
  does.
- **Admin-order reservations have no expiry.** A bank transfer can take days;
  with the 90-minute TTL the piece would go back on sale and Mark paid would then
  hit `StockUnavailable`. `ReserveStock` gains an additive `expires: bool = true`
  parameter; holds and admin orders pass `false`.
- Created as Pending: Mark paid confirms the reservations through the existing
  `ConfirmOrder` path — no change there. Created in another status: §8 Q2.
- The Edit page shows what the order holds, read-only: "1 × Bowl (from Markt 15
  Nov), 1 × Bowl (from stock)".

### 4.4 Guards

- **Lines lock (D6)** on the model, not only in the form: the `OrderLine` guard
  refuses save/delete when the order is frozen **or holds stock** (a Pending or
  Confirmed reservation referencing it). The repeater is disabled to match, with
  helper text saying why and what to do.
- **Deleting an unpaid admin order releases its stock**, in an `Order::deleted`
  hook (`deleted`, not `deleting`: a later `deleting` listener may still veto,
  and stock released for an order that survives would make its Mark paid fail).
  If the release itself fails, the reservation is orphaned — §4.5 reports it.

### 4.5 Reconcile and detection

`ReconcileStock` changes:

- A Pending, no-TTL reservation is **not** an error when it references an open
  hold, or an existing order with no payment row (the package's existing
  definition of an admin order, from `ExpireAbandonedOrders`).
- Either kind older than `inventory.hold_warn_after_days` (default 7) is
  reported as stale — a forgotten hold, or an admin order still waiting for its
  transfer.
- Everything else with no TTL stays an error — including a reservation whose
  order was deleted without releasing (§4.4).

The visible surface is the Stock holds page's age badge (D7); the reconcile
report reaches the log, as today.

### 4.6 Admin surfaces

- `StockHoldResource` in the package (Inventory). Consumers add it to their
  panel's `->resources([...])`, as IOW does for `PurchaseOrderResource`.
- `InventorySchema::stockSection()` gains the held line (D7).
- The Mark paid modal (`orders.actions.mark_paid_modal`, en + es) drops "Stock
  is not adjusted …" and says the order's held stock is confirmed. Spanish text
  is a draft for Bianka.

## 5. Periphery map — what fires differently after this brief

| Actor | Before | After |
|---|---|---|
| `ReserveStock` | TTL always applied when omitted | `expires: false` gives a reservation with no TTL; default unchanged |
| `ReleaseReservation` / `ReleaseHold` / `ReserveFromHold` | — | new movements described per hold; `ReservationReleased` / `StockReleased` / `ReservationCreated` fire during a move |
| `InventoryLogSubscriber` | logs checkout reservations | also logs hold and admin-order movements (more rows per market sale) |
| `inventory:release-expired` | releases every overdue Pending | unchanged; never sees holds or admin orders (no TTL) |
| `inventory:reconcile` / `InventoryDriftDetected` | no-TTL Pending = error | holds and admin orders exempt; stale ones reported; orphans still errors |
| `commerce:expire-orders` | skips payment-less orders | unchanged |
| `ConfirmOrder` via consumers' `HandlePaymentSucceeded` | confirms zero reservations on an admin order | confirms the admin order's reservations |
| `Order` model hooks | `deleting` refuses frozen | + `deleted` releases the order's stock |
| `OrderLine` model guard | refuses when frozen | refuses when frozen or holding stock |
| Storefront availability, cart gate | read `stock_level` | unchanged — held units are simply not for sale |
| IOW MCP stock tools (`get_stock_level`, `list_stock_levels`, `low_stock_report`, `adjust_stock`) | read/adjust `stock_level` | unchanged; held units read as lower stock, which is true |
| in-other-books | reads orders through the read API | unaffected (no stock read) |

**New public surface** (for the three `periphery.md` files): `StockHold`,
`StockHoldResource`, `ReserveFromHold`, `ReleaseHold`, the Commerce order-stock
action, `ReserveStock(expires:)`, `inventory.hold_warn_after_days`, and the
bundle contract if Q1 goes ahead.

## 6. Consumer impact on the bump (both shops)

- Migration: `stock_holds` (explicit FK names; recovery-aware `up()` — the MySQL
  DDL rule). Run the MySQL CI leg before tagging.
- `config/inventory.php`: add `hold_warn_after_days` — `PackageConfigParityTest`
  in both consumers fails until it's there, which is the intended tripwire.
- Register `StockHoldResource` in the panel. IOW: a `StockHoldPolicy extends
  AbstractBooleanPolicy` returning `canManageProducts()`, mapped in
  `registerAuthorization()`, plus rows in `PermissionPolicyTest` and
  `ResourceAccessTest`. bianka: map it to `AdminOnlyPolicy`.
- `Bundle` implements the bundle contract, and (if Q1 says so) each
  `ReserveItems` step switches to it.
- `docs/periphery.md` in all three repos.

## 7. Tests (each new guard checked red on the old code first)

- `ReserveFromHold`: full, partial (hold 1, line 2 → 1 + 1 from stock),
  refused (hold 1, line 2, stock 0 → nothing written); `stock_level` and ledger
  sum unchanged by a move; two concurrent moves from one hold serialise (MySQL leg).
- Admin order from stock: reservation has no TTL; `release-expired` leaves it;
  Mark paid confirms it; a lost race creates nothing.
- "Don't take stock": no reservations; today's behaviour.
- Lines locked when holding stock (model and form); unlocked with no stock.
- Deleting an unpaid admin order releases its stock.
- Reconcile: hold and admin-order reservations aren't errors; stale ones are
  reported after the configured days; an orphan still errors.
- Release: returns everything; twice is a no-op; a released hold can't be picked.
- Bundle line takes its components from the hold.
- IOW: a products-only admin manages holds; a content-only admin can't.

## 8. Open questions (leans in brackets)

- **Q1 — Bundles.** A bundle has no stock; each consumer expands it at checkout.
  The admin-order path lives in the package, so it needs a seam: a
  `HasStockComponents` contract (`stockComponents(): list<StockComponent>`) that
  both shops' `Bundle` implements. Switch both checkout `ReserveItems` steps to
  the same contract in the same bump, so there is one expansion rule rather than
  three? [Yes — same mechanic, same classes. Holds themselves stay products-only;
  holding five bundles means holding their components.]
- **Q2 — Orders created past Pending.** Status is chosen at creation. [Created
  Confirmed (or later): take the stock and confirm the reservations in the same
  transaction. Created Cancelled: take none. The alternative — only Pending at
  creation — is simpler but changes what admins can do today.]
- **Q3 — Names.** `StockHold`, `ReleaseHold`, `ReserveFromHold` (a `Reserve*`
  modifier — new for Inventory, so `docs/verb-families.md` gains the line), and
  a Commerce action for the order's stock step, `ReserveOrderStock`. [As listed.]
- **Q4 — Picker scope.** D4 said the picker lists holds that can cover the
  order; D5 relaxed coverage, so §4.3 lists holds holding at least one of the
  order's products. [Confirm.]
- **Q5 — Stale threshold.** One key for holds and admin orders. [7 days.]

## 9. Out of scope (flagged, not built)

- Customer self-holds and unpaid pre-release holds (D9).
- A paid manual order has no restock path: its refund actions are hidden and it
  can't be cancelled from the admin. A returned market piece goes back through a
  manual stock adjustment, as today.
- A held count in the IOW MCP stock tools.
- Moving the hold picker to each line (only if mixed-hold orders turn out to be
  common; same action underneath).

## 10. Cost and sequencing

About 2 days: one package PR, a release, then one PR per consumer (each deploys
once). Can share v0.74.0 with R4 and Complexity Release 2 if those go ahead;
otherwise its own minor. Jelte pushes the tag.
