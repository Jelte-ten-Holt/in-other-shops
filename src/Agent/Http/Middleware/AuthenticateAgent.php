<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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
 *   - `agent.is_admin` — true if bearer OR (the token carries the admin scope
 *                        AND its client is confidential + on the admin_client_ids
 *                        allowlist; see {@see self::clientMayElevate()})
 *
 * An OAuth token that passes scope checks is additionally run through the
 * optional consumer authorization gate ({@see self::passesUserGate()}). The
 * base scope is grantable to any account via the standard OAuth flow, so a
 * scope check is not an authorization check — a consumer that wants to
 * restrict *who may open a session* (e.g. operators only) sets
 * `agent.auth.oauth.user_gate` to a Gate ability. A resolved user that fails
 * it gets 403 (authenticated, not authorized) and the request does NOT fall
 * through to the bearer path. The static bearer is the operator credential and
 * is never gated.
 *
 * On 401 the response advertises the RFC 9728 protected-resource-metadata
 * URL via `WWW-Authenticate`, so OAuth-capable clients can discover how
 * to obtain a token without out-of-band config.
 */
final class AuthenticateAgent
{
    /** OAuth token authenticated and authorized — proceed. */
    private const int OAUTH_PROCEED = 1;

    /** Not a usable OAuth token (no token, wrong/absent scope) — fall through. */
    private const int OAUTH_SKIP = 0;

    /** Valid, scoped OAuth token whose user failed the consumer gate — 403. */
    private const int OAUTH_FORBIDDEN = -1;

    public function __construct(
        private readonly LogContext $logContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = (string) $request->bearerToken();

        if ($bearer !== '' && $this->oauthEnabled()) {
            $outcome = $this->authenticateViaOauth($request);

            if ($outcome === self::OAUTH_PROCEED) {
                return $next($request);
            }

            // A scoped token whose user is not authorized: fail as 403, and do
            // NOT fall through to the bearer path (that would mask the reason).
            if ($outcome === self::OAUTH_FORBIDDEN) {
                return $this->forbidden();
            }
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

    /** @return self::OAUTH_* */
    private function authenticateViaOauth(Request $request): int
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

            return self::OAUTH_SKIP;
        }

        try {
            $user = $guard->user();
        } catch (Throwable $e) {
            // The guard resolved but token validation threw. This is the path
            // that swallowed the OAuth-key-perm bug (Passport's RSA key files
            // had wrong permissions, throwing inside token decoding). Without
            // this log, that surfaces as a generic 401 with no breadcrumb.
            $this->logAuthFailure('Auth::guard("api")->user() threw during token validation.', $e);

            return self::OAUTH_SKIP;
        }

        if ($user === null) {
            return self::OAUTH_SKIP;
        }

        $token = method_exists($user, 'token') ? $user->token() : null;

        if ($token === null) {
            return self::OAUTH_SKIP;
        }

        $baseScope = (string) config('agent.auth.oauth.scope', 'agent');
        $adminScope = $this->adminScope();

        // Base scope honors wildcards (Passport's normal contract). Admin
        // requires the literal scope — a wildcard PAT must not silently
        // unlock AdjustStock and unfiltered order reads — AND the token's
        // client must be trusted to elevate (confidential AND on the
        // admin_client_ids allowlist). The scope alone is not enough: it
        // fails closed so a self-registered client that somehow obtained the
        // admin scope stays a base-scope caller.
        $hasBase = $this->tokenHasScope($token, $baseScope);
        $hasAdmin = $adminScope !== null
            && $this->tokenHasScope($token, $adminScope, strict: true)
            && $this->clientMayElevate($token);

        if (! $hasBase && ! $hasAdmin) {
            return self::OAUTH_SKIP;
        }

        // Scope grants reach — but the base scope is self-service via the
        // standard OAuth flow, so the consumer may additionally gate *which
        // users* are allowed to open a session at all. A resolved user that
        // fails the gate is authenticated-but-forbidden (403), never demoted
        // to the bearer path.
        if (! $this->passesUserGate($user)) {
            return self::OAUTH_FORBIDDEN;
        }

        $tokenId = (string) ($token->id ?? $token->getKey() ?? '');

        $this->stamp(
            request: $request,
            user: $user instanceof Authenticatable ? $user : null,
            scopes: $this->extractScopes($token),
            isAdmin: $hasAdmin,
            bearerHash: substr(hash('sha256', $tokenId), 0, 12),
        );

        return self::OAUTH_PROCEED;
    }

    /**
     * Consumer authorization gate for OAuth user tokens. When
     * `agent.auth.oauth.user_gate` names a Gate ability, the resolved user must
     * pass it. Unset (default) means no gate — every scoped token proceeds.
     *
     * Fails closed: an undefined ability makes `Gate::allows` return false, so
     * a misconfigured gate denies rather than opens the surface. The static
     * bearer never reaches here — it has no user and its own path skips this.
     */
    private function passesUserGate(?Authenticatable $user): bool
    {
        $ability = config('agent.auth.oauth.user_gate');

        if (! is_string($ability) || $ability === '') {
            return true;
        }

        if (! $user instanceof Authenticatable) {
            return false;
        }

        try {
            $allowed = Gate::forUser($user)->allows($ability);
        } catch (Throwable $e) {
            // Evaluating the gate threw (a policy touched the DB and failed, a
            // callback errored). Fail closed — a user we can't vet does not get
            // a session — but leave a breadcrumb, since this would otherwise be
            // an opaque 403.
            $this->logAuthFailure("Evaluating the agent user_gate ability [{$ability}] threw.", $e);

            return false;
        }

        if (! $allowed) {
            Log::warning('AuthenticateAgent: OAuth user failed the user_gate.', [
                'ability' => $ability,
                'user_id' => $user->getAuthIdentifier(),
            ]);
        }

        return $allowed;
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

    /**
     * Whether the OAuth token's client is trusted to elevate to admin.
     *
     * Carrying the admin scope is necessary but not sufficient — the client
     * must be confidential (operator-provisioned, holds a secret) AND on the
     * admin_client_ids allowlist. Passport's firstParty() is NOT trusted: it
     * is true for every ownerless client, including DCR-registered ones, so
     * "confidential + first-party" is self-service-satisfiable. The allowlist
     * is the only grant. Fails closed: if the client can't be resolved, no
     * elevation.
     */
    private function clientMayElevate(object $token): bool
    {
        try {
            $client = $token->client ?? null;
        } catch (Throwable $e) {
            // Resolving the client relation touched the DB and threw. Fail
            // closed — a caller we can't vet does not get admin.
            $this->logAuthFailure('Resolving the OAuth client for admin elevation threw.', $e);

            return false;
        }

        if (! is_object($client)) {
            return false;
        }

        // A public client (no secret) is never a trusted operator.
        $confidential = method_exists($client, 'confidential') && (bool) $client->confidential();

        if (! $confidential) {
            return false;
        }

        $clientId = (string) (
            (method_exists($client, 'getKey') ? $client->getKey() : null)
            ?? $client->id
            ?? ($token->client_id ?? '')
        );

        // The allowlist is the ONLY elevation grant. Passport's firstParty()
        // is deliberately NOT trusted here: it returns true for any client
        // with no owner — which includes every DCR-registered client — so a
        // "confidential first-party" check is satisfiable by the package's
        // own /register endpoint (DCR defaults to client_secret_basic, i.e.
        // confidential). Empty allowlist = no OAuth caller elevates, ever.
        return $clientId !== '' && in_array($clientId, $this->adminClientIds(), true);
    }

    /** @return array<int, string> */
    private function adminClientIds(): array
    {
        $ids = config('agent.auth.oauth.admin_client_ids', []);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map(static fn ($id): string => (string) $id, $ids));
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

    private function forbidden(): Response
    {
        // Authenticated but not authorized: the token was valid and scoped, the
        // user simply isn't permitted to use the agent surface. No
        // `WWW-Authenticate` — re-authenticating won't change the outcome.
        return response()->json(['error' => 'forbidden'], 403);
    }
}
