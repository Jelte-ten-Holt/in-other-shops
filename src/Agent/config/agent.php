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
    | v1: a single static bearer token. Empty → every request 401s (fail-closed).
    | OAuth/DCR arrives in a follow-up PR and will extend, not replace, this.
    |
    */

    'auth' => [
        'bearer_token' => env('AGENT_BEARER_TOKEN'),
    ],

];
