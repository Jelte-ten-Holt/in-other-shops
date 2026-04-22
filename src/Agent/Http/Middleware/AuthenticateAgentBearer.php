<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateAgentBearer
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('agent.auth.bearer_token', '');

        if ($expected === '' || ! hash_equals($expected, (string) $request->bearerToken())) {
            abort(401);
        }

        $request->attributes->set(
            'agent.bearer_hash',
            substr(hash('sha256', (string) $request->bearerToken()), 0, 12),
        );

        return $next($request);
    }
}
