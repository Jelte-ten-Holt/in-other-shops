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
            ToolInvocationFailed::dispatch(new ToolInvocation(
                tool: static::identifier(),
                redactedInput: $redacted,
                output: null,
                error: $e->getMessage(),
                durationMs: $this->elapsedMs($start),
                bearerHash: $bearerHash,
            ));

            throw $e;
        }

        ToolInvoked::dispatch(new ToolInvocation(
            tool: static::identifier(),
            redactedInput: $redacted,
            output: $output,
            error: null,
            durationMs: $this->elapsedMs($start),
            bearerHash: $bearerHash,
        ));

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

    /**
     * The user resolved by AuthenticateAgent, or null when the request was
     * authenticated via the static bearer (no user context) or when the
     * tool is invoked outside the /mcp middleware (in-process callers).
     * Returned as `?object` so consumer tests and duck-typed callers can
     * stamp any user-shaped value without depending on Authenticatable.
     */
    protected function currentUser(): ?object
    {
        $user = request()?->attributes->get('agent.user');

        return is_object($user) ? $user : null;
    }

    /**
     * True when the current caller holds the admin scope or the static
     * bearer. Fails closed when the middleware hasn't stamped anything —
     * in-process callers must opt-in by stamping `agent.is_admin` themselves.
     */
    protected function isAdmin(): bool
    {
        return (bool) request()?->attributes->get('agent.is_admin', false);
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
