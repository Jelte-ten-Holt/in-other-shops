<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Contracts;

interface AgentToolContract
{
    public static function identifier(): string;

    public static function displayName(): string;

    /**
     * One-line human explanation. Instance (not static) because the upstream
     * ToolInterface from opgginc/laravel-mcp-server declares it instance, and
     * PHP cannot reconcile static+instance signatures for the same method.
     */
    public function description(): string;

    /** @return array<string, mixed> */
    public function inputSchema(): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function __invoke(array $arguments): array;

    /**
     * Return a copy of the input with sensitive values redacted for logging.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function redactInput(array $arguments): array;
}
