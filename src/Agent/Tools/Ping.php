<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use InOtherShops\Agent\AgentTool;

final class Ping extends AgentTool
{
    public static function identifier(): string
    {
        return 'ping';
    }

    public static function displayName(): string
    {
        return 'Ping';
    }

    public function description(): string
    {
        return 'Health check. Returns pong and the current server time.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'echo' => [
                    'type' => 'string',
                    'description' => 'Optional string to echo back in the response.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function __invoke(array $arguments): array
    {
        return [
            'pong' => true,
            'at' => now()->toIso8601String(),
            'echo' => isset($arguments['echo']) && is_string($arguments['echo'])
                ? $arguments['echo']
                : null,
        ];
    }
}
