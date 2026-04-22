<?php

declare(strict_types=1);

namespace InOtherShops\Agent;

use InOtherShops\Agent\Contracts\AgentToolContract;
use InOtherShops\Agent\DTOs\ToolInvocation;
use InOtherShops\Agent\Events\ToolInvocationFailed;
use InOtherShops\Agent\Events\ToolInvoked;
use OPGG\LaravelMcpServer\Services\ToolService\ToolInterface;
use Throwable;

/**
 * Bridge between AgentToolContract (our domain surface) and the upstream
 * opgginc/laravel-mcp-server ToolInterface (library surface). Tools extend
 * this and implement AgentToolContract; library adaptation + event dispatch
 * live here in a single place so tools stay library-agnostic.
 */
abstract class AgentTool implements AgentToolContract, ToolInterface
{
    public function name(): string
    {
        return static::identifier();
    }

    public function annotations(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $start = hrtime(true);
        $bearerHash = $this->currentBearerHash();
        $redacted = $this->redactInput($arguments);

        try {
            $output = ($this)($arguments);
        } catch (Throwable $e) {
            event(new ToolInvocationFailed(new ToolInvocation(
                tool: static::identifier(),
                redactedInput: $redacted,
                output: null,
                error: $e->getMessage(),
                durationMs: $this->elapsedMs($start),
                bearerHash: $bearerHash,
            )));

            throw $e;
        }

        event(new ToolInvoked(new ToolInvocation(
            tool: static::identifier(),
            redactedInput: $redacted,
            output: $output,
            error: null,
            durationMs: $this->elapsedMs($start),
            bearerHash: $bearerHash,
        )));

        return $output;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function redactInput(array $arguments): array
    {
        return $arguments;
    }

    private function elapsedMs(int $startHrtime): float
    {
        return (hrtime(true) - $startHrtime) / 1_000_000;
    }

    private function currentBearerHash(): ?string
    {
        $request = request();

        $hash = $request?->attributes->get('agent.bearer_hash');

        return is_string($hash) ? $hash : null;
    }
}
