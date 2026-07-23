<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Consumer-Contributed Tools
    |--------------------------------------------------------------------------
    |
    | Class-string list of AgentToolContract implementations shipped by the
    | consuming application. These are concatenated onto the package's own
    | default tools (declared in `ToolRegistry::PACKAGE_TOOLS`) — the package
    | list cannot be wiped from here, because Laravel's `mergeConfigFrom` is
    | a shallow array_merge and would let a consumer's `tools` key silently
    | replace the package list. Keeping the defaults in PHP avoids that trap.
    |
    | Consuming apps publish their own `config/agent.php` and add their tools:
    |
    |     'tools' => [
    |         App\Project\AgentTools\SearchContent::class,
    |         // ...
    |     ],
    |
    */

    'tools' => [],

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    |
    | The package mounts one MCP endpoint. `path` is the URI (no leading slash
    | stripped by the library); `enabled` gates registration entirely.
    |
    | `throttle` is a Laravel throttle string ("requests,minutes" or a named
    | limiter) applied to every MCP request. The default is permissive enough
    | for an interactive agent session but tight enough to make bearer-token
    | brute-force unattractive. Set to a named limiter for ip+token keying.
    |
    */

    'route' => [
        'enabled' => true,
        'path' => '/mcp',
        'throttle' => env('AGENT_ROUTE_THROTTLE', '60,1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Canonical URL
    |--------------------------------------------------------------------------
    |
    | The stable, externally-reachable URL of this consumer's MCP endpoint.
    | Used as the RFC 9728 `resource` identifier in protected-resource-metadata
    | and as the RFC 8707 audience that issued OAuth tokens are bound to.
    |
    | Must be set when OAuth is enabled. For local dev you can leave it blank
    | and the resolver will fall back to `url(config('agent.route.path'))`,
    | but Co-work / remote MCP clients need a stable hostname — set this to
    | your production DNS or your named-tunnel hostname.
    |
    */

    'canonical_url' => env('AGENT_CANONICAL_URL'),

    /*
    |--------------------------------------------------------------------------
    | Server Info
    |--------------------------------------------------------------------------
    |
    | Advertised in the MCP initialize handshake.
    |
    */

    'server' => [
        'name' => env('AGENT_SERVER_NAME', 'In Other Shops Agent'),
        'version' => env('AGENT_SERVER_VERSION', '0.1.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Two paths, resolved in order by `AuthenticateAgent`:
    |
    |   1. OAuth 2.1 access token — Passport-issued, audience-bound to
    |      `agent.canonical_url`. Enabled by `auth.oauth.enabled`.
    |
    |   2. Static bearer token — the `auth.bearer_token` fallback, kept for
    |      Claude Code / MCP Inspector / other CLI clients that don't speak
    |      OAuth. Empty → that path fails closed; if OAuth is also disabled,
    |      every request 401s.
    |
    | The two can coexist: Co-work goes through OAuth, Claude Code stays on
    | the bearer. Both paths 401 with the same RFC 9728 `WWW-Authenticate`
    | header so an OAuth-capable client can discover the metadata endpoint.
    |
    */

    'auth' => [

        'bearer_token' => env('AGENT_BEARER_TOKEN'),

        'oauth' => [
            'enabled' => (bool) env('AGENT_OAUTH_ENABLED', false),

            // Base scope granted to every access token that reaches /mcp.
            // Customer-scoped tools (ListOrders, ShowOrder) filter by the
            // authenticated user's customer. The static bearer bypasses
            // scope checks entirely — it's the operator credential.
            'scope' => env('AGENT_OAUTH_SCOPE', 'agent'),

            // Elevated scope that unlocks admin-only tools (AdjustStock)
            // and un-scoped reads. Set to null to disable admin OAuth
            // entirely; admin stays reachable via the static bearer in
            // that case.
            //
            // SECURITY: `admin_scope` must NEVER be registered as an
            // interactively-grantable Passport scope (do not list it in
            // `Passport::tokensCan()`), or a self-registered DCR / public
            // client could request it and self-elevate. Carrying the scope
            // is necessary but NOT sufficient: elevation additionally
            // requires the token's client to be confidential AND listed in
            // `admin_client_ids` below (see AuthenticateAgent::clientMayElevate).
            // Passport's firstParty() is deliberately NOT trusted — it returns
            // true for every ownerless client, which includes every
            // DCR-registered client.
            'admin_scope' => env('AGENT_OAUTH_ADMIN_SCOPE', 'agent.admin'),

            // Allowlist of OAuth client ids permitted to elevate to admin
            // (in addition to being confidential). This is the ONLY elevation
            // grant: empty (the default) means NO OAuth caller elevates —
            // admin stays reachable via the static bearer only. Provision an
            // admin client through Passport directly and pin its id here.
            // Comma-separated in the env var.
            'admin_client_ids' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('AGENT_OAUTH_ADMIN_CLIENT_IDS', '')),
            ), static fn (string $id): bool => $id !== '')),

            // Optional consumer authorization gate applied to OAuth *user*
            // tokens. A token that passes scope checks additionally has its
            // resolved user run through `Gate::forUser($user)->allows($ability)`;
            // failing it returns 403 (authenticated, not authorized). This is
            // how a consumer restricts "who may open an MCP session at all"
            // (e.g. only operators) without gating every tool individually —
            // base scope alone is grantable to any account via the standard
            // OAuth flow, so a scope check is not an authorization check.
            //
            // Set to a Gate ability name defined by the consumer. Null (the
            // default) means no gate — every scoped token proceeds, preserving
            // prior behaviour. The static bearer is the operator credential and
            // is NEVER subject to this gate. Fails closed: an unknown/undefined
            // ability denies (Gate returns false), so a misconfigured gate
            // locks the surface rather than opening it.
            'user_gate' => env('AGENT_OAUTH_USER_GATE'),

            // Optional consumer admin-*elevation* gate for OAuth user tokens.
            // The twin of `user_gate`, but it decides `agent.is_admin` rather
            // than admission: when this names a Gate ability, an OAuth session
            // whose resolved user passes it is stamped admin — unlocking the
            // same gated tools the static bearer reaches.
            //
            // This is the user-identity elevation path, distinct from the
            // client allowlist above. It exists for callers that carry a
            // trustworthy authenticated user but a client that can never be
            // allowlisted (DCR-registered) nor carry the admin scope (never
            // interactively grantable) — e.g. a Co-work session logged into an
            // operator account. Trust rests on the user's own admin flag, set
            // out-of-band and never requestable, so a self-registered client
            // authenticating as a non-admin user gains nothing.
            //
            // Null (the default) means NO user-based elevation — only the
            // client-allowlist path elevates, preserving prior behaviour.
            // Fails closed: an undefined/throwing ability denies elevation
            // rather than granting it.
            'admin_gate' => env('AGENT_OAUTH_ADMIN_GATE'),

            // Reject OAuth requests that omit the RFC 8707 `resource`
            // parameter. Off by default to preserve the "single-resource
            // AS" shortcut; turn on in production once every known client
            // has been upgraded to send it.
            'require_resource' => (bool) env('AGENT_OAUTH_REQUIRE_RESOURCE', false),

            // RFC 7591 Dynamic Client Registration endpoint. `rate_limit` is
            // "requests,minutes" — clients registering too fast get a 429.
            // `initial_access_token`, when non-empty, flips DCR from open
            // to authenticated — callers must present the matching bearer.
            // `max_clients` caps the total number of DCR-registered clients
            // to bound table growth; registrations 429 once the cap is hit.
            'dcr' => [
                'enabled' => (bool) env('AGENT_DCR_ENABLED', true),
                'rate_limit' => env('AGENT_DCR_RATE_LIMIT', '5,1'),
                'initial_access_token' => env('AGENT_DCR_INITIAL_ACCESS_TOKEN'),
                'max_clients' => (int) env('AGENT_DCR_MAX_CLIENTS', 50),
            ],
        ],

    ],

];
