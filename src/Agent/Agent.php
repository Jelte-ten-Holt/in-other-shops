<?php

declare(strict_types=1);

namespace InOtherShops\Agent;

use InOtherShops\Agent\Contracts\AgentToolContract;
use InOtherShops\Agent\Support\ToolRegistry;

final class Agent
{
    public static function tools(): ToolRegistry
    {
        return app(ToolRegistry::class);
    }

    public static function tool(string $identifier): ?AgentToolContract
    {
        return self::tools()->find($identifier);
    }
}
