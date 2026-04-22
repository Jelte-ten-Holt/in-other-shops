<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use InOtherShops\Agent\Support\CanonicalUrl;
use Symfony\Component\HttpFoundation\Response;

/**
 * RFC 8707 Resource Indicators.
 *
 * Clients following the MCP authorization spec include a `resource`
 * parameter on /oauth/authorize and /oauth/token to identify the resource
 * server a token is destined for. We accept only the canonical URL of
 * this consumer's /mcp endpoint; anything else is `invalid_target`.
 *
 * Absent `resource` is tolerated — clients that pre-date RFC 8707, or
 * single-resource setups that don't need it, still work. Tokens issued
 * without `resource` are implicitly bound to this AS because the AS is
 * in-process with exactly one protected resource.
 */
final class EnforceResourceParameter
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('agent.auth.oauth.enabled', false)) {
            return $next($request);
        }

        $resource = $request->input('resource');

        if ($resource === null || $resource === '') {
            return $next($request);
        }

        $expected = CanonicalUrl::resource();

        $candidates = is_array($resource) ? $resource : [$resource];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || rtrim($candidate, '/') !== $expected) {
                return response()->json([
                    'error' => 'invalid_target',
                    'error_description' => "The requested resource is not served by this authorization server. Expected: {$expected}.",
                ], 400);
            }
        }

        return $next($request);
    }
}
