<?php

declare(strict_types=1);

namespace InOtherShops\Agent;

use InOtherShops\Agent\Contracts\AgentToolContract;
use InOtherShops\Agent\DTOs\ToolInvocation;
use InOtherShops\Agent\Events\ToolInvocationFailed;
use InOtherShops\Agent\Events\ToolInvoked;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use OPGG\LaravelMcpServer\Exceptions\Enums\JsonRpcErrorCode;
use OPGG\LaravelMcpServer\Exceptions\JsonRpcErrorException;
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

    /**
     * Human-readable tool title surfaced in MCP `tools/list` (the upstream
     * ToolRepository reads this optional hook when present). Wires to
     * {@see AgentToolContract::displayName()} so the one place a tool declares
     * its label also drives the MCP title.
     */
    public function title(): ?string
    {
        return static::displayName();
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

            // Shape errors → JSON-RPC INVALID_PARAMS so the calling agent
            // sees the actual message instead of a generic INTERNAL_ERROR.
            // Other throwables propagate untouched (the framework wraps
            // them as INTERNAL_ERROR, which is correct for unexpected
            // failures). Direct PHP callers (in-process, tests) bypass
            // execute() and still receive the original exception type.
            if ($e instanceof InvalidArgumentException) {
                throw new JsonRpcErrorException(
                    message: $e->getMessage(),
                    code: JsonRpcErrorCode::INVALID_PARAMS,
                );
            }

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

    /**
     * A failed tool-result envelope: `{ok: false, [target], error: {code, message}}`.
     * The single shape every tool returns for a business-rule failure (not-found,
     * forbidden, …). `$target` echoes the caller's addressing (type/slug/id) when
     * there is one; it is omitted otherwise.
     *
     * @param  array<string, mixed>|null  $target
     * @return array<string, mixed>
     */
    protected function failure(string $code, string $message, ?array $target = null): array
    {
        $envelope = ['ok' => false];

        if ($target !== null) {
            $envelope['target'] = $target;
        }

        $envelope['error'] = ['code' => $code, 'message' => $message];

        return $envelope;
    }

    /**
     * The pagination `meta` block shared by every list tool.
     *
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
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
