# Security Audit — in-other-shops

Date: 2026-04-24
Scope: `src/` (358 PHP files across 16 domains). Focus: Agent/OAuth, Payment, Cart API, Media, injection, crypto, rate limiting.

Manual pre-checks run: `composer audit` — **no advisories**. No `.env` in repo (library). No private keys, no hardcoded live Stripe keys in source.

## Status

Landed alongside this audit:

- **C1** — agent tools scoped. `AuthenticateAgent` now stamps `agent.user` + `agent.is_admin` on the request; the static bearer is treated as operator (admin). `ListOrders`/`ShowOrder` filter by the caller's `Customer` when non-admin; `AdjustStock` returns `forbidden` for non-admins. New OAuth `admin_scope` config (default `agent.admin`) — not grantable via DCR, provision admin clients directly.
- **H1** — DCR hardened. New `agent.auth.oauth.dcr.initial_access_token` config flips the endpoint from open to authenticated registration when non-empty. New `agent.auth.oauth.dcr.max_clients` caps total DCR clients (default 50).
- **H3** — `agent.auth.oauth.require_resource` config (default false) — when true, `EnforceResourceParameter` rejects OAuth requests missing the RFC 8707 `resource` indicator.

Still open:

- **H2** — guest-cart session-id reuse. Deferred — requires schema migration + consumer-side cart-token cookie rollout, and backwards-compat path for carts in flight.
- **M4** (DCR `client_name` sanitization), **M5** (default-redact `AgentTool::redactInput`), **M6** (webhook `eventId` contract), **M7** (document PKCE enforcement), **M1/M2/M3** (morph-class gadget, mass-assignment, `/mcp` throttle). All tracked below.

---

## Critical

### C1. Agent tools return unscoped global data

- [src/Agent/Tools/ListOrders.php:70](../../../src/Agent/Tools/ListOrders.php#L70) — `Commerce::order()::query()` returns every order in the DB.
- [src/Agent/Tools/ShowOrder.php:48](../../../src/Agent/Tools/ShowOrder.php#L48) — `Order::find($id)` returns any order.
- [src/Agent/Tools/AdjustStock.php:112](../../../src/Agent/Tools/AdjustStock.php#L112) — any agent token can write stock on any model.

Combined with DCR enabled by default ([src/Agent/config/agent.php:104](../../../src/Agent/config/agent.php#L104)) issuing non-expiring clients, anyone who walks a logged-in user through `/oauth/authorize` with `scope=agent` gets full order read + stock write.

**PoC**: register a DCR client → run the authorization flow against a logged-in shop user → `POST /mcp` with `list_orders` → full order dump.

**Fix**: scope list/show tools to the authenticated user's `customer_id`; split `AdjustStock` behind a separate admin-only sub-scope (e.g. `agent.admin`).

---

## High

### H1. DCR allows unlimited never-expiring public client registration

[src/Agent/Http/Controllers/DynamicClientRegistrationController.php:117-131](../../../src/Agent/Http/Controllers/DynamicClientRegistrationController.php#L117-L131) returns `client_secret_expires_at: 0` with no `registration_access_token` / `registration_client_uri`. Only rate limit is `5,1` per IP. An attacker fills `oauth_clients` with unrevokable entries.

RFC 7591 §4 recommends an initial access token for authenticated registration.

**Fix**: config-gated initial-access-token requirement; TTL on `client_secret_expires_at`; DB-level cap rejecting when total DCR clients > N.

### H2. Guest cart keyed on raw PHP session id (cart fixation)

[src/Commerce/Cart/Http/Support/ResolveCurrentCart.php:25](../../../src/Commerce/Cart/Http/Support/ResolveCurrentCart.php#L25) passes `session()->getId()` as the cart key; persisted in `carts.session_token`. If a consumer skips `session()->regenerate()` on login, pre-seeded session ids inject cart items the victim later claims. Separately, the session id is a bearer credential and shouldn't live in a business table.

**Fix**: dedicated cryptographically random `cart_token` cookie (HttpOnly, SameSite=Lax), decoupled from session lifecycle.

### H3. EnforceResourceParameter tolerates missing `resource`

[src/Agent/Http/Middleware/EnforceResourceParameter.php:35](../../../src/Agent/Http/Middleware/EnforceResourceParameter.php#L35) — absent `resource` is accepted. Fine when the AS is in-process with one protected resource; if a consumer ever mounts Passport against multiple APIs, tokens become cross-resource bearers.

**Fix**: add `agent.auth.oauth.require_resource` config (default false, document = true in production).

---

## Medium

### M1. `AddToCartRequest` instantiates class from user-controlled `type`

[src/Commerce/Cart/Http/Requests/AddToCartRequest.php:71-77](../../../src/Commerce/Cart/Http/Requests/AddToCartRequest.php#L71-L77) falls back to `new $class` on `class_exists($class)`. Any autoloadable class with a side-effecting constructor becomes a gadget.

**Fix**: require `Relation::getMorphedModel($type)` resolution — reject unknown morph types.

### M2. Mass-assignment reach across 23 models with `$guarded = []`

All models use `$guarded = []` per the package convention. Package-internal controllers pass validated DTOs, but [src/Commerce/Order/Actions/CreateOrder.php:170](../../../src/Commerce/Order/Actions/CreateOrder.php#L170) takes `array $addressData` and calls `$order->addresses()->make($addressData)` — a consumer passing `$request->all()` in lets the caller set `type`, foreign keys, etc.

**Fix**: validate the address shape before `make()`, or guard the Address model explicitly.

### M3. MCP endpoint has no rate limit

[src/Agent/AgentServiceProvider.php:59-63](../../../src/Agent/AgentServiceProvider.php#L59-L63) — `/mcp` sits behind `AuthenticateAgent` only. A valid bearer can hammer tools — DoS the stock-movement table via `AdjustStock`, or drive cost on `ListOrders`.

**Fix**: `throttle:60,1` or config-driven.

### M4. DCR `client_name` unsanitized

[src/Agent/Http/Controllers/DynamicClientRegistrationController.php:106](../../../src/Agent/Http/Controllers/DynamicClientRegistrationController.php#L106) — unbounded string. Rendered in `AgentLogSubscriber` and potentially in Filament admin views. A `<script>` in `client_name` could XSS an admin console.

**Fix**: length cap + strip HTML/control chars.

### M5. `AgentTool::redactInput()` default is pass-through

[src/Agent/AgentTool.php:73-76](../../../src/Agent/AgentTool.php#L73-L76) — default returns arguments unredacted. Every tool must remember to override or secrets log in cleartext.

**Fix**: default-redact-all, opt-in to safe fields.

### M6. Webhook idempotency skipped when `eventId` is null

[src/Payment/Actions/HandlePaymentWebhook.php:57](../../../src/Payment/Actions/HandlePaymentWebhook.php#L57) — `if ($payload->eventId === null) return true;`. Stripe always supplies one, so Stripe is safe. A custom gateway returning null has zero replay protection even though signature verification passed.

**Fix**: treat null `eventId` as a contract violation — require gateways to supply it.

### M7. PKCE advertised but no server-side `require_pkce` surfaced

[src/Agent/Http/Controllers/AuthorizationServerMetadataController.php:31](../../../src/Agent/Http/Controllers/AuthorizationServerMetadataController.php#L31) advertises `S256`. Enforcement relies on Passport's own behavior (PKCE required for public clients only). OAuth 2.1 recommends PKCE for all clients.

**Fix**: document or config-gate global PKCE enforcement.

---

## Low / Informational

- [src/Commerce/Cart/Http/Requests/UpdateCartItemRequest.php:19](../../../src/Commerce/Cart/Http/Requests/UpdateCartItemRequest.php#L19) — `min:1` conflicts with README's "0 removes". Non-security.
- [src/Media/Actions/StoreMedia.php](../../../src/Media/Actions/StoreMedia.php) — no MIME/extension validation; relies on caller. Document the constraint for consumers who expose StoreMedia via public endpoints.
- [src/Agent/Http/Middleware/AuthenticateAgent.php:110](../../../src/Agent/Http/Middleware/AuthenticateAgent.php#L110) — correct `hash_equals` use for static bearer. Clean.
- No `shell_exec`/`exec`/`eval`/`unserialize` anywhere. Clean.
- No `whereRaw`/`orderByRaw`/`havingRaw` taking user input. Single `DB::raw` usage at [src/Payment/Concerns/InteractsWithPayments.php:31](../../../src/Payment/Concerns/InteractsWithPayments.php#L31) is a hardcoded expression with no interpolation. Clean.
- [src/Commerce/Order/Support/RandomOrderNumberGenerator.php:23](../../../src/Commerce/Order/Support/RandomOrderNumberGenerator.php#L23) — `Str::random(8)` is CSPRNG-backed. Clean.
- Cart item ownership ([src/Commerce/Cart/Http/Controllers/CartItemController.php](../../../src/Commerce/Cart/Http/Controllers/CartItemController.php) — `ensureItemBelongsToCurrentCart`) present and correct. No IDOR on `/api/cart/items/{item}`. Clean.
- Cart unit price is server-derived via `$cartable->getCartableUnitPrice()`, not client-supplied. No price tampering. Clean.
- Webhook signature verification uses `Stripe\Webhook::constructEvent` with tolerance. Webhook idempotency ledger uses unique-constraint pattern when `event_id` present. Clean (modulo M6).
- [.gitignore](../../../.gitignore) is minimal but correct for a library — no `.env` entry because the package has no `.env`. Clean.

---

## CSRF note for consumers

[src/Commerce/CommerceServiceProvider.php:47](../../../src/Commerce/CommerceServiceProvider.php#L47) defaults cart middleware to `['web']` — which includes `VerifyCsrfToken`. This is correct for browser SPA consumers. Consumers who switch to `['api']` to "fix" a CSRF 419 lose CSRF protection without any compensating SameSite/Origin check. Worth a note in the README.

---

## Top fixes, in order

1. **Scope the agent tools** (C1) — most impactful package change; split `AdjustStock` behind a dedicated admin scope.
2. **Harden DCR** (H1) — initial access token + TTL + client cap.
3. **Throttle `/mcp`** (M3) — one-line addition.
4. **Switch guest cart to a dedicated cart_token** (H2) — a small migration, fixes fixation + removes the session-id-as-bearer anti-pattern.

---

## Related app-side findings

Consumers of this package (e.g. `in-other-worlds`) also need to address:
- Stored XSS via markdown `v-html` pipeline — consumer-side issue, but exploitable through `CreateContentDraft` agent tool when bodies are shipped to `v-html` without escaping.
- Single shared `AGENT_BEARER_TOKEN` — consumers should scope tokens per user.

See `in-other-worlds/docs/audits/2026-04-24/security-audit.md` for those.
