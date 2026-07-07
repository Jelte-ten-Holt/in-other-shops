<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InOtherShops\Agent\Http\Middleware\AuthenticateAgent;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Direct unit-shaped coverage of the AuthenticateAgent middleware.
 *
 * The McpEndpointTest exercises the middleware via the /mcp route — that
 * proves wiring. This file proves the middleware's contract: which request
 * attributes it stamps on success, what shape the 401 response takes, and
 * the edge cases (empty bearer header, OAuth-disabled-but-bearer-set).
 *
 * The OAuth-via-Passport branches are NOT covered here because Passport is
 * not a dev dependency of this package; they're verified end-to-end against
 * the consuming app's Passport setup.
 */
final class AuthenticateAgentMiddlewareTest extends TestCase
{
    private const string BEARER = 'middleware-test-bearer-xyz';

    private const string PROBE_PATH = '/agent-test/probe';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('agent.auth.bearer_token', self::BEARER);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware([AuthenticateAgent::class])
            ->get(self::PROBE_PATH, fn (Request $request): array => [
                'agent.user' => $request->attributes->get('agent.user'),
                'agent.scopes' => $request->attributes->get('agent.scopes'),
                'agent.is_admin' => $request->attributes->get('agent.is_admin'),
                'agent.bearer_hash' => $request->attributes->get('agent.bearer_hash'),
            ]);
    }

    #[Test]
    public function a_valid_bearer_request_reaches_the_route(): void
    {
        $this->getJson(self::PROBE_PATH, [
            'Authorization' => 'Bearer '.self::BEARER,
        ])->assertOk();
    }

    #[Test]
    public function a_valid_bearer_marks_the_caller_as_admin(): void
    {
        // Bearer holders are operators by construction (per the middleware
        // docblock). If this regresses, the AdjustStock tool's admin guard
        // would silently reject CLI operators.
        $body = $this->getJson(self::PROBE_PATH, [
            'Authorization' => 'Bearer '.self::BEARER,
        ])->assertOk()->json();

        $this->assertTrue($body['agent.is_admin']);
    }

    #[Test]
    public function a_valid_bearer_establishes_an_agent_audit_actor(): void
    {
        // The boundary actor for /mcp: every stock/order mutation the agent makes
        // is audit-attributed to it ambiently (F21, P2). The bearer path has no
        // OAuth principal, so it's labelled as the operator.
        $this->getJson(self::PROBE_PATH, [
            'Authorization' => 'Bearer '.self::BEARER,
        ])->assertOk();

        $actor = $this->app->make(\InOtherShops\Logging\LogContext::class)->actor();
        $this->assertNotNull($actor);
        $this->assertSame(\InOtherShops\Logging\Enums\LogActorType::Agent, $actor->type);
        $this->assertSame('bearer operator', $actor->label);
    }

    #[Test]
    public function a_valid_bearer_stamps_both_base_and_admin_scopes(): void
    {
        $body = $this->getJson(self::PROBE_PATH, [
            'Authorization' => 'Bearer '.self::BEARER,
        ])->assertOk()->json();

        $this->assertContains('agent', $body['agent.scopes']);
        $this->assertContains('agent.admin', $body['agent.scopes']);
    }

    #[Test]
    public function a_valid_bearer_stamps_a_short_hashed_identifier_not_the_raw_token(): void
    {
        // Sanity: bearer_hash MUST NOT be the raw token. Logs and request
        // attributes flow into observability tools; raw tokens leaking there
        // would be a credential leak.
        $body = $this->getJson(self::PROBE_PATH, [
            'Authorization' => 'Bearer '.self::BEARER,
        ])->assertOk()->json();

        $hash = $body['agent.bearer_hash'];
        $this->assertIsString($hash);
        $this->assertSame(12, strlen($hash));
        $this->assertNotSame(self::BEARER, $hash);
        $this->assertStringNotContainsString(self::BEARER, $hash);
    }

    #[Test]
    public function a_valid_bearer_leaves_agent_user_null_because_bearer_is_userless(): void
    {
        $body = $this->getJson(self::PROBE_PATH, [
            'Authorization' => 'Bearer '.self::BEARER,
        ])->assertOk()->json();

        $this->assertNull($body['agent.user']);
    }

    #[Test]
    public function a_missing_authorization_header_rejects_with_401_and_stamps_no_attributes(): void
    {
        $this->getJson(self::PROBE_PATH)
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized'])
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    #[Test]
    public function a_wrong_bearer_rejects_with_401(): void
    {
        $this->getJson(self::PROBE_PATH, [
            'Authorization' => 'Bearer not-the-right-token',
        ])->assertStatus(401);
    }

    #[Test]
    public function an_empty_bearer_value_in_the_header_rejects_with_401(): void
    {
        // Distinct from the missing-header case. Some clients send
        // `Authorization: Bearer ` with an empty token; that must also 401
        // rather than silently match an empty config bearer.
        $this->getJson(self::PROBE_PATH, [
            'Authorization' => 'Bearer ',
        ])->assertStatus(401);
    }

    #[Test]
    public function an_empty_bearer_config_rejects_a_request_that_sends_an_empty_bearer(): void
    {
        // Critical: when bearer is unconfigured (empty string), `hash_equals('', '')`
        // would be `true`. The middleware must early-exit on empty config.
        config()->set('agent.auth.bearer_token', '');

        $this->getJson(self::PROBE_PATH, [
            'Authorization' => 'Bearer ',
        ])->assertStatus(401);
    }

    #[Test]
    public function the_oauth_disabled_path_advertises_a_plain_bearer_challenge(): void
    {
        config()->set('agent.auth.oauth.enabled', false);

        $this->getJson(self::PROBE_PATH)
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    #[Test]
    public function the_oauth_enabled_path_advertises_the_resource_metadata_url(): void
    {
        config()->set('agent.auth.oauth.enabled', true);
        config()->set('agent.canonical_url', 'https://agent.example.test/mcp');

        $this->getJson(self::PROBE_PATH)
            ->assertStatus(401)
            ->assertHeader(
                'WWW-Authenticate',
                'Bearer resource_metadata="https://agent.example.test/.well-known/oauth-protected-resource"',
            );
    }

    #[Test]
    public function oauth_enabled_with_no_passport_falls_back_to_bearer_authentication(): void
    {
        // OAuth check tries `Auth::guard('api')`; without Passport that throws
        // or returns null. The middleware must catch and continue to the
        // static bearer path so CLI clients still authenticate. Assert on
        // the bearer-path stamp (`agent.bearer_hash`) — `assertOk` alone
        // would also pass if some other (non-bearer) path happened to
        // succeed, which is exactly the regression we're guarding against.
        config()->set('agent.auth.oauth.enabled', true);

        $body = $this->getJson(self::PROBE_PATH, [
            'Authorization' => 'Bearer '.self::BEARER,
        ])->assertOk()->json();

        $this->assertNotEmpty($body['agent.bearer_hash'],
            'Bearer fallback must stamp the bearer-hash attribute, proving the bearer path was taken.');
        $this->assertTrue($body['agent.is_admin']);
    }

    #[Test]
    public function a_guard_resolution_failure_is_logged_at_warning_level(): void
    {
        // Regression: previously the catch silently returned false, making
        // misconfigured OAuth setups (broken provider config, missing driver)
        // invisible. The catch must leave a breadcrumb.
        config()->set('agent.auth.oauth.enabled', true);
        Log::spy();

        $this->getJson(self::PROBE_PATH, [
            'Authorization' => 'Bearer '.self::BEARER,
        ])->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'AuthenticateAgent')
                && str_contains($message, 'guard("api") failed to resolve')
                && isset($context['exception'])
                && isset($context['message']))
            ->once();
    }

    #[Test]
    public function a_token_validation_failure_is_logged_at_warning_level(): void
    {
        // The OAuth-key-perm bug surfaced here: Passport's `$guard->user()`
        // threw while reading the RSA keys, the catch swallowed it, and the
        // operator only saw a generic 401. This proves the breadcrumb is now
        // emitted on that exact path.
        config()->set('agent.auth.oauth.enabled', true);
        config()->set('auth.guards.api', ['driver' => 'throwing-stub', 'provider' => null]);

        Auth::extend('throwing-stub', fn () => new class implements Guard
        {
            public function check(): bool
            {
                return false;
            }

            public function guest(): bool
            {
                return true;
            }

            public function user(): never
            {
                throw new RuntimeException('simulated token validation failure');
            }

            public function id(): null
            {
                return null;
            }

            public function validate(array $credentials = []): bool
            {
                return false;
            }

            public function hasUser(): bool
            {
                return false;
            }

            public function setUser($user): self
            {
                return $this;
            }
        });

        Log::spy();

        $this->getJson(self::PROBE_PATH, [
            'Authorization' => 'Bearer '.self::BEARER,
        ])->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'AuthenticateAgent')
                && str_contains($message, 'threw during token validation')
                && ($context['exception'] ?? null) === RuntimeException::class
                && ($context['message'] ?? null) === 'simulated token validation failure')
            ->once();
    }

    #[Test]
    public function the_happy_bearer_path_does_not_emit_auth_failure_warnings(): void
    {
        // OAuth disabled → the middleware skips the guard resolution branch
        // entirely, so neither catch site fires and no warning is emitted.
        config()->set('agent.auth.oauth.enabled', false);
        Log::spy();

        $this->getJson(self::PROBE_PATH, [
            'Authorization' => 'Bearer '.self::BEARER,
        ])->assertOk();

        Log::shouldNotHaveReceived('warning');
    }

    // -----------------------------------------------------------------------
    // T-SEC1 — admin elevation requires a trusted client, not just the scope.
    //
    // Passport is not a dev dependency, so these fake the OAuth path with a
    // stub `api` guard returning a user whose `token()` carries scopes + a
    // duck-typed client (confidential()/firstParty()/getKey()) — the exact
    // shape AuthenticateAgent reads.
    // -----------------------------------------------------------------------

    #[Test]
    public function an_admin_scope_token_from_a_public_client_is_not_elevated(): void
    {
        // The headline exploit: a self-registered (public) client that carries
        // the admin scope must NOT get is_admin — it degrades to base access.
        $this->actAsOauthClient(['agent', 'agent.admin'], $this->fakeClient('public-1', confidential: false, firstParty: false));

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertFalse($body['agent.is_admin'], 'A public client with the admin scope must not elevate.');
        // Side-effect check: it still authenticates at base scope (not a 401),
        // proving the gate strips elevation rather than rejecting the request.
        $this->assertContains('agent', $body['agent.scopes']);
    }

    #[Test]
    public function an_admin_scope_token_from_an_allowlisted_confidential_client_is_elevated(): void
    {
        config()->set('agent.auth.oauth.admin_client_ids', ['trusted-9']);

        $this->actAsOauthClient(['agent', 'agent.admin'], $this->fakeClient('trusted-9', confidential: true, firstParty: false));

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertTrue($body['agent.is_admin']);
    }

    #[Test]
    public function a_confidential_first_party_client_off_the_allowlist_is_not_elevated(): void
    {
        // Regression guard for the DCR bypass: Passport's firstParty() is true
        // for ANY ownerless client — which includes every DCR-registered one,
        // and DCR's default auth method mints confidential clients. So
        // "confidential + first-party" is attacker-satisfiable via /register
        // and must NOT elevate. The allowlist is the only grant.
        config()->set('agent.auth.oauth.admin_client_ids', []);

        $this->actAsOauthClient(['agent', 'agent.admin'], $this->fakeClient('dcr-ownerless', confidential: true, firstParty: true));

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertFalse($body['agent.is_admin'], 'firstParty() must not be trusted for elevation — DCR clients satisfy it.');
        $this->assertContains('agent', $body['agent.scopes']);
    }

    #[Test]
    public function an_allowlisted_but_public_client_is_not_elevated(): void
    {
        // The allowlist grants elevation only to confidential clients — an id
        // match alone must not rescue a secretless client.
        config()->set('agent.auth.oauth.admin_client_ids', ['leaked-id']);

        $this->actAsOauthClient(['agent', 'agent.admin'], $this->fakeClient('leaked-id', confidential: false, firstParty: false));

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertFalse($body['agent.is_admin']);
    }

    #[Test]
    public function a_confidential_client_that_is_not_allowlisted_is_not_elevated(): void
    {
        // Confidential alone is not enough — the allowlist is the only grant,
        // and empty allowlist means no OAuth caller elevates at all.
        config()->set('agent.auth.oauth.admin_client_ids', []);

        $this->actAsOauthClient(['agent', 'agent.admin'], $this->fakeClient('rando-confidential', confidential: true, firstParty: false));

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertFalse($body['agent.is_admin']);
        $this->assertContains('agent', $body['agent.scopes']);
    }

    #[Test]
    public function a_base_scope_token_from_a_trusted_client_is_not_admin(): void
    {
        // Elevation still requires the admin scope: an allowlisted confidential
        // client without the admin scope stays base-only.
        config()->set('agent.auth.oauth.admin_client_ids', ['trusted-9']);

        $this->actAsOauthClient(['agent'], $this->fakeClient('trusted-9', confidential: true, firstParty: false));

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertFalse($body['agent.is_admin']);
    }

    // -----------------------------------------------------------------------
    // Consumer user-gate — restrict *which* OAuth users may open a session.
    //
    // `agent.auth.oauth.user_gate` names a Gate ability; a scoped token whose
    // user fails it gets 403 (not 401, not a silent bearer fallthrough). The
    // static bearer is never gated. See AuthenticateAgent::passesUserGate().
    // -----------------------------------------------------------------------

    #[Test]
    public function an_oauth_user_that_passes_the_user_gate_proceeds(): void
    {
        Gate::define('use-mcp', fn ($user): bool => true);
        config()->set('agent.auth.oauth.user_gate', 'use-mcp');

        $this->actAsOauthClient(['agent'], $this->fakeClient('c1', confidential: false, firstParty: false));

        $this->oauthProbe()->assertOk();
    }

    #[Test]
    public function an_oauth_user_that_fails_the_user_gate_is_forbidden_with_403(): void
    {
        // 403, not 401: the token is valid and scoped, the user simply isn't
        // permitted. No WWW-Authenticate — re-authenticating won't help.
        Gate::define('use-mcp', fn ($user): bool => false);
        config()->set('agent.auth.oauth.user_gate', 'use-mcp');

        $this->actAsOauthClient(['agent'], $this->fakeClient('c1', confidential: false, firstParty: false));

        $this->oauthProbe()
            ->assertStatus(403)
            ->assertExactJson(['error' => 'forbidden'])
            ->assertHeaderMissing('WWW-Authenticate');
    }

    #[Test]
    public function no_user_gate_configured_admits_any_scoped_oauth_user(): void
    {
        // Back-compat: with user_gate unset, a scoped token proceeds regardless
        // of user — preserving pre-gate behaviour for consumers (e.g. bianka)
        // that don't set one.
        config()->set('agent.auth.oauth.user_gate', null);

        $this->actAsOauthClient(['agent'], $this->fakeClient('c1', confidential: false, firstParty: false));

        $this->oauthProbe()->assertOk();
    }

    #[Test]
    public function an_undefined_user_gate_ability_fails_closed_with_403(): void
    {
        // Misconfiguration guard: user_gate names an ability the consumer never
        // defined. Gate::allows returns false → 403; the gate never opens the
        // surface by default.
        config()->set('agent.auth.oauth.user_gate', 'ability-that-does-not-exist');

        $this->actAsOauthClient(['agent'], $this->fakeClient('c1', confidential: false, firstParty: false));

        $this->oauthProbe()->assertStatus(403);
    }

    #[Test]
    public function the_static_bearer_is_never_subject_to_the_user_gate(): void
    {
        // The bearer is the operator credential. Even a deny-all gate must not
        // lock it out — in production the bearer isn't a Passport token, so the
        // OAuth path skips (no user resolved) and the bearer path admits it
        // ungated. No stub guard here: mirrors that production shape.
        Gate::define('use-mcp', fn ($user): bool => false);
        config()->set('agent.auth.oauth.enabled', true);
        config()->set('agent.auth.oauth.user_gate', 'use-mcp');

        $body = $this->getJson(self::PROBE_PATH, ['Authorization' => 'Bearer '.self::BEARER])
            ->assertOk()->json();

        $this->assertTrue($body['agent.is_admin']);
    }

    #[Test]
    public function a_user_gate_denial_is_logged_at_warning_level(): void
    {
        Gate::define('use-mcp', fn ($user): bool => false);
        config()->set('agent.auth.oauth.user_gate', 'use-mcp');
        $this->actAsOauthClient(['agent'], $this->fakeClient('c1', confidential: false, firstParty: false));
        Log::spy();

        $this->oauthProbe()->assertStatus(403);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'user_gate')
                && ($context['ability'] ?? null) === 'use-mcp')
            ->once();
    }

    // -----------------------------------------------------------------------
    // Consumer admin-gate — user-identity elevation for OAuth callers whose
    // client can never be allowlisted (DCR / Co-work) nor carry the admin
    // scope. `agent.auth.oauth.admin_gate` names a Gate ability; a resolved
    // user that passes it is stamped is_admin, independent of the client.
    // See AuthenticateAgent::passesAdminGate().
    // -----------------------------------------------------------------------

    #[Test]
    public function an_oauth_user_that_passes_the_admin_gate_is_elevated(): void
    {
        Gate::define('elevate-mcp', fn ($user): bool => true);
        config()->set('agent.auth.oauth.admin_gate', 'elevate-mcp');

        // Base scope only, public non-allowlisted client — the Co-work shape.
        // Elevation here can ONLY come from the user gate, not the client path.
        $this->actAsOauthClient(['agent'], $this->fakeClient('dcr-cowork', confidential: true, firstParty: true));

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertTrue($body['agent.is_admin'], 'A user passing admin_gate must elevate even from a non-allowlisted client.');
    }

    #[Test]
    public function an_oauth_user_that_fails_the_admin_gate_stays_base_scope(): void
    {
        Gate::define('elevate-mcp', fn ($user): bool => false);
        config()->set('agent.auth.oauth.admin_gate', 'elevate-mcp');

        $this->actAsOauthClient(['agent'], $this->fakeClient('dcr-cowork', confidential: true, firstParty: true));

        $body = $this->oauthProbe()->assertOk()->json();

        // Not elevated, but still admitted at base scope (the gate strips
        // elevation, it does not reject the session — that's the user_gate's job).
        $this->assertFalse($body['agent.is_admin']);
        $this->assertContains('agent', $body['agent.scopes']);
    }

    #[Test]
    public function no_admin_gate_configured_leaves_oauth_users_unelevated(): void
    {
        // Back-compat: with admin_gate unset, OAuth users never elevate via the
        // user path — only the client allowlist can, preserving prior behaviour
        // for consumers (e.g. bianka) that don't set one.
        config()->set('agent.auth.oauth.admin_gate', null);

        $this->actAsOauthClient(['agent'], $this->fakeClient('c1', confidential: true, firstParty: true));

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertFalse($body['agent.is_admin']);
    }

    #[Test]
    public function an_undefined_admin_gate_ability_fails_closed_to_unelevated(): void
    {
        // Misconfiguration guard: admin_gate names an ability the consumer never
        // defined. Gate::allows returns false → no elevation. Fails closed, same
        // as the user gate — a bad config never grants admin.
        config()->set('agent.auth.oauth.admin_gate', 'ability-that-does-not-exist');

        $this->actAsOauthClient(['agent'], $this->fakeClient('c1', confidential: true, firstParty: true));

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertFalse($body['agent.is_admin']);
        $this->assertContains('agent', $body['agent.scopes']);
    }

    #[Test]
    public function the_admin_gate_is_independent_of_the_client_allowlist(): void
    {
        // The headline case: a Co-work-shaped caller — base scope, no admin
        // scope, confidential+firstParty DCR client that is NOT allowlisted —
        // is exactly the shape the client path rejects. The admin_gate is the
        // only thing that can elevate it, and it does, keyed on the user.
        config()->set('agent.auth.oauth.admin_client_ids', []);
        Gate::define('elevate-mcp', fn ($user): bool => true);
        config()->set('agent.auth.oauth.admin_gate', 'elevate-mcp');

        $this->actAsOauthClient(['agent'], $this->fakeClient('dcr-ownerless', confidential: true, firstParty: true));

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertTrue($body['agent.is_admin']);
    }

    #[Test]
    public function a_throwing_admin_gate_policy_fails_closed_and_is_logged(): void
    {
        Gate::define('elevate-mcp', function ($user): bool {
            throw new RuntimeException('simulated admin_gate policy failure');
        });
        config()->set('agent.auth.oauth.admin_gate', 'elevate-mcp');
        $this->actAsOauthClient(['agent'], $this->fakeClient('c1', confidential: true, firstParty: true));
        Log::spy();

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertFalse($body['agent.is_admin'], 'A throwing admin_gate must fail closed — no elevation.');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'admin_gate')
                && ($context['exception'] ?? null) === RuntimeException::class)
            ->once();
    }

    private function oauthProbe(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(self::PROBE_PATH, ['Authorization' => 'Bearer oauth-access-token']);
    }

    /**
     * Register a stub `api` guard whose user carries a token with the given
     * scopes and client.
     *
     * @param  array<int, string>  $scopes
     */
    private function actAsOauthClient(array $scopes, object $client): void
    {
        config()->set('agent.auth.oauth.enabled', true);
        config()->set('auth.guards.api', ['driver' => 'agent-oauth-stub', 'provider' => null]);

        $token = new class($scopes, $client)
        {
            public ?int $id = 4242;

            public string|int|null $client_id;

            /** @param array<int, string> $scopes */
            public function __construct(public array $scopes, public object $client)
            {
                $this->client_id = $client->getKey();
            }

            public function can(string $scope): bool
            {
                return in_array($scope, $this->scopes, true) || in_array('*', $this->scopes, true);
            }
        };

        $user = new class($token) implements \Illuminate\Contracts\Auth\Authenticatable
        {
            public function __construct(private object $tk) {}

            public function token(): object
            {
                return $this->tk;
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): int
            {
                return 99;
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getRememberToken(): string
            {
                return '';
            }

            public function setRememberToken($value): void {}

            public function getRememberTokenName(): string
            {
                return '';
            }
        };

        Auth::extend('agent-oauth-stub', fn () => new class($user) implements Guard
        {
            public function __construct(private object $usr) {}

            public function check(): bool
            {
                return true;
            }

            public function guest(): bool
            {
                return false;
            }

            public function user(): object
            {
                return $this->usr;
            }

            public function id(): int
            {
                return 99;
            }

            public function validate(array $credentials = []): bool
            {
                return true;
            }

            public function hasUser(): bool
            {
                return true;
            }

            public function setUser($user): self
            {
                return $this;
            }
        });
    }

    /**
     * A duck-typed Passport-Client stand-in: the middleware only calls
     * confidential(), firstParty() and getKey() on it.
     */
    private function fakeClient(string|int $id, bool $confidential, bool $firstParty): object
    {
        return new class($id, $confidential, $firstParty)
        {
            public function __construct(
                private string|int $id,
                private bool $confidential,
                private bool $firstParty,
            ) {}

            public function confidential(): bool
            {
                return $this->confidential;
            }

            public function firstParty(): bool
            {
                return $this->firstParty;
            }

            public function getKey(): string|int
            {
                return $this->id;
            }
        };
    }
}
