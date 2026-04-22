<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use InOtherShops\Agent\Support\ToolRegistry;
use OPGG\LaravelMcpServer\Enums\ProtocolVersion;

Route::mcp(config('agent.route.path', '/mcp'))
    ->setServerInfo(
        name: config('agent.server.name'),
        version: config('agent.server.version'),
    )
    ->setProtocolVersion(ProtocolVersion::V2025_11_25)
    ->tools(app(ToolRegistry::class)->classes());
