# Agent Domain

Bearer-gated MCP (Streamable HTTP) endpoint for locally-run Claude clients. Ships a `ToolRegistry`, library-adapter base class, audit log subscriber, and nine generic shop tools (ping, catalog, taxonomy, orders, stock). Consuming projects append their own tools via `config/agent.php`.

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

`ToolRegistry` is a singleton. Its class list is `ToolRegistry::PACKAGE_TOOLS` (the nine generic shop tools) concatenated with `config('agent.tools', [])` (the consumer's own tools). The package list is kept in PHP rather than in `config/agent.php` because `mergeConfigFrom` is a shallow array merge — a consumer that publishes a `config/agent.php` with its own `tools` key would otherwise wipe the package defaults silently. Consumers add to the list, they do not replace it.

### Route

`AgentServiceProvider::boot()` registers `Route::mcp(config('agent.route.path'))` wrapped in the `AuthenticateAgent` middleware group. The route is mounted at the app root — no `/api` prefix, no `api` middleware group. Tools go through the library's `Route::mcp(...)->tools([...])` registration, which resolves each class through the container.

When `agent.auth.oauth.enabled` is true, the provider also mounts (again at app root):

- `GET /.well-known/oauth-protected-resource` — RFC 9728 metadata for the /mcp resource.
- `GET /.well-known/oauth-authorization-server` — RFC 8414 metadata for the in-process Passport authorization server.
- `POST /oauth/register` — RFC 7591 Dynamic Client Registration, rate-limited.

### Authentication

`AuthenticateAgent` resolves in two steps:

1. **OAuth access token** — if `agent.auth.oauth.enabled`, the middleware checks `Auth::guard('api')` (Passport). Tokens must carry the `agent` scope. Audience binding against `agent.canonical_url` is enforced by the token issuer (see OAuth section below).
2. **Static bearer** — `config('agent.auth.bearer_token')`, compared via `hash_equals`. Empty token → that path fails closed.

Both paths populate the `agent.bearer_hash` request attribute (short SHA-256 prefix) for log correlation. For OAuth tokens the hash is of the token id, not the access token string.

On 401 the response carries `WWW-Authenticate: Bearer resource_metadata="..."` per RFC 9728 §5.1, pointing at the protected-resource-metadata endpoint so an OAuth-capable client can discover how to obtain a token.

### Audit logging

`AgentLogSubscriber` routes `ToolInvoked` / `ToolInvocationFailed` through `LogDispatcher` on the `agent` channel. Payload: tool identifier, redacted input, duration, bearer hash, and — for failures — error message. Tools override `redactInput()` to drop sensitive values before logging.

## Configuration

`config/agent.php`:

```php
'tools'         => [/* consumer tools — package tools ship in ToolRegistry::PACKAGE_TOOLS */],
'route'         => ['enabled' => true, 'path' => '/mcp'],
'server'        => ['name' => 'In Other Shops Agent', 'version' => '0.1.0'],
'canonical_url' => env('AGENT_CANONICAL_URL'),
'auth' => [
    'bearer_token' => env('AGENT_BEARER_TOKEN'),
    'oauth' => [
        'enabled' => (bool) env('AGENT_OAUTH_ENABLED', false),
        'scope'   => env('AGENT_OAUTH_SCOPE', 'agent'),
        'dcr' => [
            'enabled'    => (bool) env('AGENT_DCR_ENABLED', true),
            'rate_limit' => env('AGENT_DCR_RATE_LIMIT', '5,1'),
        ],
    ],
],
```

`canonical_url` is the stable, externally-reachable URL of the consumer's `/mcp` endpoint — used as the RFC 9728 resource identifier and the RFC 8707 audience. When OAuth is enabled it should be set to the consumer's production DNS or named-tunnel hostname; for local dev it can be blank and falls back to `url(config('agent.route.path'))`.

## Dependencies

- **Logging** — `LogDispatcher`, `LogEntry`, `LogLevel`
- **opgginc/laravel-mcp-server** — Streamable HTTP transport, JSON-RPC, `tools/list` / `tools/call` handlers
