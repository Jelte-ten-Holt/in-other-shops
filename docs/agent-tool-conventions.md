# Agent tool conventions

Conventions for authoring tools in the `Agent` domain — both package-shipped tools (`src/Agent/Tools/`) and consumer project tools (e.g. `app/Project/AgentTools/` in In Other Worlds).

The goal is predictability. An LLM caller should be able to infer a new tool's shape from the ones it already knows, and a human reader of the `agent` log channel should see a uniform payload across every invocation.

---

## Input conventions

### Identifiers

- **Typed-browsable tools** use `{type, slug}`. `type` is a key from `config('storefront.models')` and resolves to a model class. `slug` is the browsable slug.
- **Commerce resources** use `{id}` (integer, `minimum: 1`).
- No composite identifiers (no `product:foo` URI strings, no `type/slug` path forms). Keep identifiers as separate typed fields.

### Pagination

List tools accept:

- `page` — integer, `minimum: 1`. Defaults to `1`.
- `per_page` — integer, `minimum: 1`, `maximum: 100`. Defaults are tool-specific (typically 20 or 25).

Cursor pagination is not used. If a tool's output set ever outgrows offset pagination, add a cursor field — don't mutate `page` semantics.

### Filtering

Filter parameters are domain-specific and named after what they filter:

- `category`, `tag`, `search` — free-form strings.
- `status`, `tag_type` — enum-like; use JSON-schema `enum` when the value set is finite (`OrderStatus::cases()` pattern).
- `from` / `to` — dates, JSON-schema `format: date` (`YYYY-MM-DD`).
- `include_inactive` — booleans default `false`; name them positively.

### Ordering

- `sort` — single string; prefix with `-` for descending (`-created_at`). Tools document the allowed keys in the parameter `description`.

### What tools do NOT accept (v1)

- **No `associations` / `include` / `with`** — eager-load controls. Each tool ships a fixed output shape; if a caller needs a different shape, write a different tool.
- **No sparse fieldsets** (`fields=...`).
- **No `limit` independent of `per_page`.**

These can be added later if a real use case appears. Don't pre-implement them.

---

## Output conventions

Every tool returns one of three shapes. The key at the root is always `ok: bool`.

### A. Single-entity tools

`show_*`, `get_*`, and mutations that target a single record.

**Success:**

```json
{
  "ok": true,
  "target": { "type": "product", "slug": "boxed-set" },
  "data": { "stock_level": 17, "movement_id": 48, "delta_applied": 5 }
}
```

**Expected negative outcome (entity missing, invariant refused):**

```json
{
  "ok": false,
  "target": { "type": "product", "slug": "does-not-exist" },
  "error": { "code": "not_found", "message": "No product with slug 'does-not-exist'." }
}
```

- `target` echoes the identifier fields the caller supplied. For `{type, slug}` tools that means both. For `{id}` tools, `{ "id": 42 }`.
- `data` is always nested (never flattened into the envelope) so sibling fields (`warnings`, `side_effects`) can be added without reshaping.
- `error.code` is a snake_case stable string callers can branch on. `error.message` is human-readable.

### B. List tools

`browse_*`, `list_*`.

**Paginated:**

```json
{
  "ok": true,
  "data": [ { "...": "..." } ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 20, "total": 47 }
}
```

**Unpaginated (small finite sets — categories, tags, formats):**

```json
{ "ok": true, "data": [ { "...": "..." } ] }
```

- Empty result is `ok: true` with `data: []`, not `ok: false`. A filter that matches nothing is a successful outcome.
- `meta` is present only when pagination is applied.

### C. Ambient tools

`ping`, health, self-describing tools (`list_authoring_options`, `list_content_formats`).

```json
{ "ok": true, "data": { "pong": true, "at": "2026-04-22T10:15:00Z" } }
```

- No `target` — nothing to echo.
- `data` is still nested so the shape matches A and B.

---

## Error handling: `ok: false` vs. throwing

Two distinct concerns.

**`ok: false`** is for *expected-but-negative* outcomes that the LLM caller should branch on:

- Entity lookup missed (`not_found`)
- Domain invariant refused the operation (`published_row_refused`, `not_stockable`)
- Caller-visible business rule rejected the input (`duplicate_tag_attachment` — though prefer idempotency where feasible)

**Throw an exception** for *caller bugs* — malformed or nonsensical inputs that should surface as a JSON-RPC error, not a structured tool response:

- Unknown `type` key (not in `storefront.models`)
- Schema-violating input that slipped past the library's schema check
- Referenced config that doesn't exist

The library converts exceptions into JSON-RPC error envelopes at the transport layer. A correctly-written LLM prompt doesn't need to branch on those — they're bugs, not outcomes.

### Error code vocabulary

Codes are snake_case. Maintained as tools ship. Current codes:

| Code | Meaning |
|---|---|
| `not_found` | Identifier resolved to no row |
| `not_stockable` | Resolved model does not implement `HasStock` |
| `published_row_refused` | Draft-only mutation attempted on a published row |
| `invariant_refused` | Generic invariant rejection; prefer a specific code when one fits |

Add a new code by adding a row here. Don't reuse an existing code for a new meaning.

---

## Worked examples

### `ping` (ambient)

```json
// request
{ "name": "ping", "arguments": {} }
// response
{ "ok": true, "data": { "pong": true, "at": "2026-04-22T10:15:00Z" } }
```

### `show_order` (single-entity)

```json
// hit
{ "ok": true, "target": { "id": 42 }, "data": { "id": 42, "status": "paid", "...": "..." } }

// miss
{ "ok": false, "target": { "id": 9999 }, "error": { "code": "not_found", "message": "No order with id 9999." } }
```

### `browse_catalog` (list)

```json
{
  "ok": true,
  "data": [ /* BrowsableResource[] */ ],
  "meta": { "current_page": 1, "last_page": 2, "per_page": 20, "total": 23 }
}
```

### `adjust_stock` (single-entity mutation)

```json
// applied
{
  "ok": true,
  "target": { "type": "product", "slug": "boxed-set" },
  "data": {
    "previous_stock_level": 12,
    "stock_level": 17,
    "delta_applied": 5,
    "reason": "restock",
    "movement_id": 48
  }
}

// miss
{
  "ok": false,
  "target": { "type": "product", "slug": "missing-product" },
  "error": { "code": "not_found", "message": "No product with slug 'missing-product'." }
}
```

---

## Checklist for a new tool

1. Implementation extends `AgentTool` and implements `AgentToolContract`.
2. `identifier()` is snake_case, verb-led for actions (`adjust_stock`), noun-led for queries (`list_orders`).
3. Inputs follow the conventions above (`type`/`slug` or `id`; `page`/`per_page` for lists; enums in JSON-schema `enum`).
4. Output follows shape A, B, or C — with `ok`, `target` (where applicable), `data`, and `error`/`meta` as needed.
5. Negative outcomes the caller might reasonably branch on return `ok: false` with an `error.code` from the vocabulary (add a row if new).
6. Caller bugs throw.
7. `redactInput()` overridden if the input carries sensitive values.
8. One feature test per meaningful path (happy, each negative branch, each thrown condition).
9. Tool registered in `config('agent.tools')` of either the package or the consumer project.
