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
 * Tolerance for missing `resource` is controlled by
 * `agent.auth.oauth.require_resource`:
 *   - false (default): absent `resource` is accepted. Single-resource AS
 *     shortcut — tokens issued without `resource` are implicitly bound
 *     to this AS because it has exactly one protected resource.
 *   - true: absent `resource` is rejected as `invalid_target`. Production
 *     setups with more than one MCP endpoint should flip this on.
 */
final class EnforceResourceParameter
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('agent.auth.oauth.enabled', false)) {
            return $next($request);
        }

        $resource = $request->input('resource');
        $expected = CanonicalUrl::resource();

        if ($resource === null || $resource === '') {
            if ((bool) config('agent.auth.oauth.require_resource', false)) {
                return $this->invalidTarget($expected);
            }

            return $next($request);
        }

        $candidates = is_array($resource) ? $resource : [$resource];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || rtrim($candidate, '/') !== $expected) {
                return $this->invalidTarget($expected);
            }
        }

        return $next($request);
    }

    private function invalidTarget(string $expected): Response
    {
        return response()->json([
            'error' => 'invalid_target',
            'error_description' => "The requested resource is not served by this authorization server. Expected: {$expected}.",
        ], 400);
    }
}
