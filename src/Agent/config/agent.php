<?php

declare(strict_types=1);

use InOtherShops\Agent\Tools\BrowseCatalog;
use InOtherShops\Agent\Tools\GetStockLevel;
use InOtherShops\Agent\Tools\ListCategories;
use InOtherShops\Agent\Tools\ListOrders;
use InOtherShops\Agent\Tools\ListTags;
use InOtherShops\Agent\Tools\Ping;
use InOtherShops\Agent\Tools\ShowBrowsable;
use InOtherShops\Agent\Tools\ShowOrder;

return [

    /*
    |--------------------------------------------------------------------------
    | Registered Tools
    |--------------------------------------------------------------------------
    |
    | Class-string list of AgentToolContract implementations. The package seeds
    | generic shop tools; consuming projects append project-specific tools via
    | their own config/agent.php (Laravel merges the arrays).
    |
    */

    'tools' => [
        Ping::class,
        BrowseCatalog::class,
        ShowBrowsable::class,
        ListCategories::class,
        ListTags::class,
        ListOrders::class,
        ShowOrder::class,
        GetStockLevel::class,
    ],

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
