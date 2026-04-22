<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InOtherShops\Agent\Support\CanonicalUrl;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Resolver for the /mcp endpoint.
 *
 * Order of checks:
 *
 *   1. OAuth 2.1 access token (Passport-issued) via the `api` guard, if
 *      OAuth is enabled in config. Token must carry the `agent` scope.
 *
 *   2. Static `config('agent.auth.bearer_token')` bearer, for CLI clients
 *      (Claude Code, MCP Inspector) that don't speak OAuth.
 *
 * On 401 the response advertises the RFC 9728 protected-resource-metadata
 * URL via `WWW-Authenticate`, so OAuth-capable clients can discover how
 * to obtain a token without out-of-band config.
 */
final class AuthenticateAgent
{
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
        } catch (Throwable) {
            return false;
        }

        try {
            $user = $guard->user();
        } catch (Throwable) {
            return false;
        }

        if ($user === null) {
            return false;
        }

        $token = method_exists($user, 'token') ? $user->token() : null;

        if ($token === null) {
            return false;
        }

        $requiredScope = (string) config('agent.auth.oauth.scope', 'agent');

        if (! $this->tokenHasScope($token, $requiredScope)) {
            return false;
        }

        $tokenId = (string) ($token->id ?? $token->getKey() ?? '');

        $request->attributes->set(
            'agent.bearer_hash',
            substr(hash('sha256', $tokenId), 0, 12),
        );

        return true;
    }

    private function tokenHasScope(object $token, string $scope): bool
    {
        if (method_exists($token, 'can')) {
            return (bool) $token->can($scope);
        }

        $scopes = property_exists($token, 'scopes') ? $token->scopes : null;

        if (is_array($scopes)) {
            return in_array($scope, $scopes, true) || in_array('*', $scopes, true);
        }

        return false;
    }

    private function authenticateViaStaticBearer(string $bearer, Request $request): bool
    {
        $expected = (string) config('agent.auth.bearer_token', '');

        if ($expected === '' || ! hash_equals($expected, $bearer)) {
            return false;
        }

        $request->attributes->set(
            'agent.bearer_hash',
            substr(hash('sha256', $bearer), 0, 12),
        );

        return true;
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
