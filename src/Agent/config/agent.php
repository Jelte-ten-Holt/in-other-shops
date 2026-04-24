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
    */

    'route' => [
        'enabled' => true,
        'path' => '/mcp',
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
            // and un-scoped reads. Not grantable via DCR — provision
            // admin clients through Passport directly. Set to null to
            // disable admin OAuth entirely; admin stays reachable via
            // the static bearer in that case.
            'admin_scope' => env('AGENT_OAUTH_ADMIN_SCOPE', 'agent.admin'),

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
