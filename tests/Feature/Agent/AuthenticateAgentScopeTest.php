<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\Http\Middleware\AuthenticateAgent;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * Regression cover for H1 (audit 2026-05-09): the admin scope check must
 * reject wildcard ('*') tokens.
 *
 * Passport's Token::can() returns true for any token holding '*', and the
 * project never registers the 'agent.admin' scope via Passport::tokensCan(),
 * so before this fix a personal-access token with `['*']` would silently
 * unlock the admin path even though no DCR client could ever request it.
 *
 * Direct reflection on the private method because spinning up a real
 * Passport token requires Passport as a dev-dep (it isn't). The
 * tokenHasScope() contract is what the middleware delegates to; pinning it
 * here is the cheapest way to keep H1 closed.
 */
final class AuthenticateAgentScopeTest extends TestCase
{
    private AuthenticateAgent $middleware;

    private ReflectionMethod $tokenHasScope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = $this->app->make(AuthenticateAgent::class);
        $this->tokenHasScope = new ReflectionMethod($this->middleware, 'tokenHasScope');
        $this->tokenHasScope->setAccessible(true);
    }

    #[Test]
    public function wildcard_token_passes_base_scope_check(): void
    {
        // Wildcard remains valid for the base scope — that's Passport's normal
        // contract and we don't want to break legitimate operator workflows.
        $token = $this->fakeToken(['*']);

        $this->assertTrue(
            $this->tokenHasScope->invoke($this->middleware, $token, 'agent', false)
        );
    }

    #[Test]
    public function wildcard_token_rejected_for_admin_scope_in_strict_mode(): void
    {
        // The admin path must require the literal scope. This is the H1 fix.
        $token = $this->fakeToken(['*']);

        $this->assertFalse(
            $this->tokenHasScope->invoke($this->middleware, $token, 'agent.admin', true),
            'Wildcard token must NOT pass the admin scope check (H1).'
        );
    }

    #[Test]
    public function literal_admin_scope_passes_strict_mode(): void
    {
        // Tokens explicitly granted agent.admin still work — strict mode is
        // about rejecting the wildcard shortcut, not breaking real admin grants.
        $token = $this->fakeToken(['agent', 'agent.admin']);

        $this->assertTrue(
            $this->tokenHasScope->invoke($this->middleware, $token, 'agent.admin', true)
        );
    }

    #[Test]
    public function token_without_admin_scope_fails_strict_mode(): void
    {
        $token = $this->fakeToken(['agent']);

        $this->assertFalse(
            $this->tokenHasScope->invoke($this->middleware, $token, 'agent.admin', true)
        );
    }

    /**
     * Mimics Passport's Token shape closely enough for tokenHasScope:
     * a public `scopes` property and a `can()` method that honors `'*'`.
     */
    private function fakeToken(array $scopes): object
    {
        return new class($scopes)
        {
            /** @var array<int, string> */
            public array $scopes;

            /** @param array<int, string> $scopes */
            public function __construct(array $scopes)
            {
                $this->scopes = $scopes;
            }

            public function can(string $scope): bool
            {
                return in_array('*', $this->scopes, true)
                    || in_array($scope, $this->scopes, true);
            }
        };
    }
}
