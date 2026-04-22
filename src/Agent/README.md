# Agent Domain

Bearer-gated MCP (Streamable HTTP) endpoint for locally-run Claude clients. Ships a `ToolRegistry`, library-adapter base class, audit log subscriber, and a seed `ping` tool. Consuming projects append their own tools via `config/agent.php`.

**Writing a new tool? Read [docs/agent-tool-conventions.md](../../docs/agent-tool-conventions.md) first.** It defines the input, output, and error conventions every tool — package or project — must follow.

## Architecture

### AgentToolContract

Every tool implements:

```php
interface AgentToolContract
{
    public static function identifier(): string;
    public static function displayName(): string;

    public function description(): string;
    public function inputSchema(): array;
    public function __invoke(array $arguments): array;
    public function redactInput(array $arguments): array;
}
```

`identifier()` and `displayName()` are **static** — mirrors the `CtaContract` / `SidebarModuleContract` convention on the consuming project side. `description()` is **instance** only because the upstream `ToolInterface` (opgginc/laravel-mcp-server) declares it instance and PHP cannot reconcile static+instance signatures for the same method on one class. That's the single deviation from the registry-contract convention, forced by the library.

### AgentTool base class

`AgentTool` (abstract) is the single point of coupling to `opgginc/laravel-mcp-server`. It implements the library's `ToolInterface` in terms of `AgentToolContract` methods, and dispatches `ToolInvoked` / `ToolInvocationFailed` around each call. Tools extend `AgentTool` and implement `AgentToolContract` — the library surface is invisible to tool authors. Swapping the library means editing `AgentTool`, not rewriting tools.

### Registry

`ToolRegistry` is a singleton constructed from `config('agent.tools', [])` — a flat class-string array. Package seeds generic shop tools; consumers append project tools via their own `config/agent.php` (Laravel's config merge).

### Route

`AgentServiceProvider::boot()` registers one route: `Route::mcp(config('agent.route.path'))` wrapped in the `AuthenticateAgentBearer` middleware group. The route is mounted at the app root — no `/api` prefix, no `api` middleware group. Tools go through the library's `Route::mcp(...)->tools([...])` registration, which resolves each class through the container.

### Authentication

`AuthenticateAgentBearer` compares the request bearer against `config('agent.auth.bearer_token')` via `hash_equals`. Empty token → 401 (fail-closed — never allow-all). The short SHA-256 prefix of the bearer is stashed on the request for log correlation.

OAuth/DCR for Anthropic Co-work acceptance arrives in a follow-up PR and extends, not replaces, this path.

### Audit logging

`AgentLogSubscriber` routes `ToolInvoked` / `ToolInvocationFailed` through `LogDispatcher` on the `agent` channel. Payload: tool identifier, redacted input, duration, bearer hash, and — for failures — error message. Tools override `redactInput()` to drop sensitive values before logging.

## Configuration

`config/agent.php`:

```php
'tools'  => [Ping::class, /* ...project tools */],
'route'  => ['enabled' => true, 'path' => '/mcp'],
'server' => ['name' => 'In Other Shops Agent', 'version' => '0.1.0'],
'auth'   => ['bearer_token' => env('AGENT_BEARER_TOKEN')],
```

## Dependencies

- **Logging** — `LogDispatcher`, `LogEntry`, `LogLevel`
- **opgginc/laravel-mcp-server** — Streamable HTTP transport, JSON-RPC, `tools/list` / `tools/call` handlers
