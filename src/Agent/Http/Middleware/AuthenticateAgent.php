<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use InOtherShops\Agent\Support\CanonicalUrl;
use InOtherShops\Logging\DTOs\LogActor;
use InOtherShops\Logging\LogContext;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Resolver for the /mcp endpoint.
 *
 * Order of checks:
 *
 *   1. OAuth 2.1 access token (Passport-issued) via the `api` guard, if
 *      OAuth is enabled in config. Token must carry the base scope
 *      (`auth.oauth.scope`) or the admin scope (`auth.oauth.admin_scope`);
 *      the admin scope implies the base.
 *
 *   2. Static `config('agent.auth.bearer_token')` bearer, for CLI clients
 *      (Claude Code, MCP Inspector) that don't speak OAuth. Bearer holders
 *      are operators — admin by construction.
 *
 * On success the request gets three attributes stamped:
 *
 *   - `agent.user`     — the authenticated user (OAuth) or null (bearer)
 *   - `agent.scopes`   — the granted scope list
 *   - `agent.is_admin` — true if bearer OR the token carries admin scope
 *
 * On 401 the response advertises the RFC 9728 protected-resource-metadata
 * URL via `WWW-Authenticate`, so OAuth-capable clients can discover how
 * to obtain a token without out-of-band config.
 */
final class AuthenticateAgent
{
    public function __construct(
        private readonly LogContext $logContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = (string) $request->bearerToken();

        if ($bearer !== '' && $this->oauthEnabled() && $this->authenticateViaOauth($request)) {
            return $next($request);
        }

        if ($bearer !== '' && $this->authenticateViaStaticBearer($bearer, $request)) {
            return $next($request);
        }

        return $this->unauthorized();
    }

    private function oauthEnabled(): bool
    {
        return (bool) config('agent.auth.oauth.enabled', false);
    }

    private function authenticateViaOauth(Request $request): bool
    {
        try {
            $guard = Auth::guard('api');
        } catch (Throwable $e) {
            // The api guard isn't resolvable. Common in environments without
            // Passport (CLI / dev / staging that runs the bearer path only) —
            // noisy but harmless there. The signal we care about is the rare
            // case where Passport IS installed and the guard still fails to
            // resolve (misconfigured providers, broken DI). Without this log
            // that case is invisible.
            $this->logAuthFailure('Auth::guard("api") failed to resolve.', $e);

            return false;
        }

        try {
            $user = $guard->user();
        } catch (Throwable $e) {
            // The guard resolved but token validation threw. This is the path
            // that swallowed the OAuth-key-perm bug (Passport's RSA key files
            // had wrong permissions, throwing inside token decoding). Without
            // this log, that surfaces as a generic 401 with no breadcrumb.
            $this->logAuthFailure('Auth::guard("api")->user() threw during token validation.', $e);

            return false;
        }

        if ($user === null) {
            return false;
        }

        $token = method_exists($user, 'token') ? $user->token() : null;

        if ($token === null) {
            return false;
        }

        $baseScope = (string) config('agent.auth.oauth.scope', 'agent');
        $adminScope = $this->adminScope();

        // Base scope honors wildcards (Passport's normal contract). Admin
        // requires the literal scope — a wildcard PAT must not silently
        // unlock AdjustStock and unfiltered order reads.
        $hasBase = $this->tokenHasScope($token, $baseScope);
        $hasAdmin = $adminScope !== null && $this->tokenHasScope($token, $adminScope, strict: true);

        if (! $hasBase && ! $hasAdmin) {
            return false;
        }

        $tokenId = (string) ($token->id ?? $token->getKey() ?? '');

        $this->stamp(
            request: $request,
            user: $user instanceof Authenticatable ? $user : null,
            scopes: $this->extractScopes($token),
            isAdmin: $hasAdmin,
            bearerHash: substr(hash('sha256', $tokenId), 0, 12),
        );

        return true;
    }

    private function tokenHasScope(object $token, string $scope, bool $strict = false): bool
    {
        $scopes = property_exists($token, 'scopes') ? $token->scopes : null;

        if ($strict) {
            return is_array($scopes) && in_array($scope, $scopes, true);
        }

        if (method_exists($token, 'can')) {
            return (bool) $token->can($scope);
        }

        if (is_array($scopes)) {
            return in_array($scope, $scopes, true) || in_array('*', $scopes, true);
        }

        return false;
    }

    /** @return array<int, string> */
    private function extractScopes(object $token): array
    {
        $scopes = property_exists($token, 'scopes') ? $token->scopes : null;

        if (is_array($scopes)) {
            return array_values(array_filter(
                array_map(static fn ($s) => is_string($s) ? $s : null, $scopes),
            ));
        }

        return [];
    }

    private function authenticateViaStaticBearer(string $bearer, Request $request): bool
    {
        $expected = (string) config('agent.auth.bearer_token', '');

        if ($expected === '' || ! hash_equals($expected, $bearer)) {
            return false;
        }

        $this->stamp(
            request: $request,
            user: null,
            scopes: $this->bearerScopes(),
            isAdmin: true,
            bearerHash: substr(hash('sha256', $bearer), 0, 12),
        );

        return true;
    }

    /** @return array<int, string> */
    private function bearerScopes(): array
    {
        $scopes = [(string) config('agent.auth.oauth.scope', 'agent')];

        $admin = $this->adminScope();
        if ($admin !== null) {
            $scopes[] = $admin;
        }

        return $scopes;
    }

    private function adminScope(): ?string
    {
        $scope = config('agent.auth.oauth.admin_scope', 'agent.admin');

        return is_string($scope) && $scope !== '' ? $scope : null;
    }

    /** @param  array<int, string>  $scopes */
    private function stamp(
        Request $request,
        ?Authenticatable $user,
        array $scopes,
        bool $isAdmin,
        string $bearerHash,
    ): void {
        $request->attributes->set('agent.user', $user);
        $request->attributes->set('agent.scopes', $scopes);
        $request->attributes->set('agent.is_admin', $isAdmin);
        $request->attributes->set('agent.bearer_hash', $bearerHash);

        // Boundary actor for the /mcp request: every stock/order/etc. mutation
        // this agent makes is audit-attributed to it ambiently (brief, §3). The
        // bearer hash is a stable per-token fingerprint (never the raw token);
        // OAuth requests additionally name their authenticated principal.
        $this->logContext->setActor(LogActor::agent(
            id: $bearerHash,
            label: $user !== null ? 'oauth:'.$user->getAuthIdentifier() : 'bearer operator',
        ));
    }

    private function logAuthFailure(string $message, Throwable $e): void
    {
        Log::warning('AuthenticateAgent: '.$message, [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }

    private function unauthorized(): Response
    {
        $header = 'Bearer';

        if ($this->oauthEnabled()) {
            $metadataUrl = CanonicalUrl::issuer().'/.well-known/oauth-protected-resource';
            $header = sprintf('Bearer resource_metadata="%s"', $metadataUrl);
        }

        return response()->json(['error' => 'unauthorized'], 401, [
            'WWW-Authenticate' => $header,
        ]);
    }
}
