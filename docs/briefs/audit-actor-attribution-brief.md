# Brief: audit-log actor attribution (audit F21 + F22)

> Status: **design settled, build-ready** (discuss pass done 2026-06-04/05 with Jelte). Primarily package
> (`in-other-shops`: Logging + the boundaries that emit audit events) with a small consumer middleware
> (`in-other-worlds`). One release window (~v0.33.0), mostly additive / non-breaking. Stacks on v0.32.0.
>
> Closes **F21** (no actor on any `domain_logs` row) and the remaining half of **F22** (G10 already did the
> *resilience* half — the audit echo can't fail or corrupt the business action; this adds the *who*).

## Problem

Every `domain_logs` row records *what* happened and *when* (`level · channel · message · context · created_at`)
but never *who*. The `LogContext` object that was designed to carry ambient info into every entry is **never
populated** — so the entire audit trail is anonymous. With the agent/MCP connector and Stripe webhooks both able to
mutate stock/orders/payments, "who adjusted this / issued this refund / confirmed this order" is unanswerable.

## Settled design

### 1. A structured `LogActor` value object (Logging domain)
```php
enum LogActorType: string { case User; case Gateway; case System; case Agent; }

final readonly class LogActor {
    public function __construct(
        public LogActorType $type,
        public ?string $id,     // user id / oauth client / command name (null where N/A)
        public string $label,   // human-readable: "Jelte", "stripe", "commerce:expire-orders"
    ) {}

    public static function user(string $id, string $label): self;   // an authenticated admin/customer
    public static function guest(): self;                           // User type, id null, label "guest"
    public static function gateway(string $name): self;             // Gateway type, label = gateway name
    public static function system(string $source): self;            // System type, label = command/process
    public static function agent(string $id, string $label): self;  // Agent type (MCP connector)
    public static function unknown(): self;                         // System type, label "unknown" — the loud default
}
```
(Mirrors the existing `RefundActor` vocabulary — see §4 — so the two stay consistent.)

### 2. Stored in dedicated columns (not the context JSON)
`domain_logs` gains `actor_type`, `actor_id` (nullable), `actor_label`. The whole point is to *query* by actor
("everything the agent touched", "rows with `actor_label = 'unknown'`"), which a nested JSON field can't do well.
The table is an append-only, prunable echo, so the migration is cheap and there's no backfill: pre-migration rows
read `actor_type = null` and are treated as unknown.

`LogEntry` gains an additive `?LogActor $actor = null`; `DatabaseLogHandler` writes the three columns.

### 3. Ambient-by-default, explicit-override — with a strict precedence
The actor is a property of the **request/job boundary**, not of the deep event. So it is set **once** at each
boundary and every entry produced downstream inherits it automatically — which is what dissolves the
"package-set events can't pass an actor" problem: `AdjustStock` fires `StockAdjusted`, the subscriber writes a
row, and the actor is *already* in `LogContext` from the boundary. No action signature or event has to thread it.

A handful of operations know their actor better than the ambient request does (refunds). Those carry an explicit
actor on the event, which **overrides** the ambient one for that entry. The dispatcher resolves, per entry:

> **explicit (`LogEntry.actor`) → ambient (`LogContext::actor()`) → `LogActor::unknown()`**

`LogDispatcher::enrichEntry()` applies this precedence and stamps the resolved actor on the entry handed to
handlers. We do **not** add an actor field to every event — ambient covers the 90%; only operations that define
their own actor carry one.

### 4. `LogActor` vs `RefundActor` — derive, don't merge
They are different layers and both stay:
- `RefundActor` (`Commerce/Order/DTOs`) is durable **business** data on the `refunds` row — who issued the refund.
- `LogActor` (`Logging`) is the cross-cutting **audit-log** actor.

The refund audit path (`CommerceLogSubscriber::handleRefundRecorded`) **derives** a `LogActor` from the refund's
`RefundActor` (`admin → user`, `gateway → gateway`) and passes it explicitly, so the two never disagree. This is
Jelte's "a RefundActor should trigger a LogActor that logs the refund."

### 5. Correctness: request-scoped, no leak
`LogContext` is a singleton, so the ambient actor must be **set per request/job and cleared/overwritten**, never
carried across. Today every audit `*LogSubscriber` is **synchronous** (runs in-request), so they inherit the
boundary actor and there is no queue-leak risk. Guard rail for the future: **if any audit-writing listener is ever
made `ShouldQueue`, it must capture the actor at dispatch and re-establish it in the job** — a queued listener
running later would otherwise inherit a worker's stale or empty context. Documented so it can't be missed.

## The actor map (who sets it, where)

| Boundary | Sets | Where |
|---|---|---|
| Consumer web/admin request | `LogActor::user(id, name)` (authed) or `::guest()` | **new consumer middleware** `SetAuditActor` (web + Filament panel groups) — package can't, it doesn't own auth |
| Stripe webhook | `LogActor::gateway('stripe')` | package `ProcessPaymentWebhook`, before dispatching events |
| Scheduled command | `LogActor::system('<signature>')` | each package command (`commerce:expire-orders`, `inventory:release-expired`, `*:prune-*`) in `handle()` — via a small shared concern |
| Agent / MCP call | `LogActor::agent(client, label)` | package `AuthenticateAgent` middleware, after resolving the agent identity |
| Refund (any source) | derived from `RefundActor` | explicit on the refund log entry (§4) |
| Anything unset | `LogActor::unknown()` | dispatcher default — should be ~0 rows in prod; a tripwire if not |

## Build phases (committed per phase, suite green each)

- **P1 (package — Logging core):** `LogActorType` enum + `LogActor` VO; `LogEntry.actor`; `LogContext`
  set/get/forget actor; `LogDispatcher` precedence; `DatabaseLogHandler` columns + migration. Tests: precedence
  (explicit > ambient > unknown), column round-trip, unknown default.
- **P2 (package — boundaries):** webhook handler (gateway), scheduled commands (system, shared concern), agent
  middleware (agent); derive `LogActor` from `RefundActor` in the refund log path. Tests per boundary.
- **P3 (package — verification):** extend the G3 `AuditPipelineRowTest` to assert the `actor_*` columns on a real
  row per channel (closes the F21 "is it actually recorded" gap end-to-end).
- **P4 (consumer):** `SetAuditActor` middleware (user/guest) on web + admin; version bump + `composer update`;
  assert an admin-driven mutation logs `actor_type = user`.
- **Release:** ~v0.33.0 (additive/non-breaking).

## Open questions (minor — confirm before/while building)
1. **Guest checkout actor** — `LogActor::guest()` (a User-type actor with no id) is proposed. Alternative: treat a
   customer checkout as `user(customerId)` and reserve `guest()` only for truly anonymous writes. Lean: `guest()`
   for unauthenticated, `user()` for authenticated (admin or customer).
2. **Index** on `actor_type` (and/or `actor_label`)? Scale is low; likely skip until a query needs it (per
   [[project_scale_expectations]]).
3. **Enum breadth** — 4 cases (User/Gateway/System/Agent) proposed; `guest()` folds into User. Confirm no 5th.

## Scope / blast radius
Package: Logging domain + 3 boundary touch-points + the refund log path + a migration. Consumer: one middleware +
its registration. All additive — no signature breaks, new columns nullable. Per [[feedback_logging_scope]] this
stays audit-events-only (stock/pricing/orders/payments/lifecycle); it does not start logging media/taxonomy admin
activity. Single-admin today, so the immediate payoff is **distinguishing human vs gateway vs cron vs agent**
writes, not which admin.
