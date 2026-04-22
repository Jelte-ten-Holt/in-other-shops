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

            // Scope required on every access token. Single scope is
            // deliberate — per-tool permissions are out of scope for v1.
            'scope' => env('AGENT_OAUTH_SCOPE', 'agent'),

            // RFC 7591 Dynamic Client Registration endpoint. `rate_limit` is
            // "requests,minutes" — clients registering too fast get a 429.
            'dcr' => [
                'enabled' => (bool) env('AGENT_DCR_ENABLED', true),
                'rate_limit' => env('AGENT_DCR_RATE_LIMIT', '5,1'),
            ],
        ],

    ],

];
