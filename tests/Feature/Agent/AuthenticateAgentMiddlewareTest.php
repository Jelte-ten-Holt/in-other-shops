<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function an_admin_scope_token_from_a_confidential_first_party_client_is_elevated(): void
    {
        // First-party confidential clients are operator-provisioned and elevate
        // without needing to be listed in admin_client_ids.
        $this->actAsOauthClient(['agent', 'agent.admin'], $this->fakeClient('first-party', confidential: true, firstParty: true));

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertTrue($body['agent.is_admin']);
    }

    #[Test]
    public function a_confidential_client_that_is_neither_first_party_nor_allowlisted_is_not_elevated(): void
    {
        // Confidential alone is not enough — proves the allowlist/first-party
        // gate, not just the public/confidential split.
        config()->set('agent.auth.oauth.admin_client_ids', []);

        $this->actAsOauthClient(['agent', 'agent.admin'], $this->fakeClient('rando-confidential', confidential: true, firstParty: false));

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertFalse($body['agent.is_admin']);
        $this->assertContains('agent', $body['agent.scopes']);
    }

    #[Test]
    public function a_base_scope_token_from_a_trusted_client_is_not_admin(): void
    {
        // Elevation still requires the admin scope: a trusted (confidential
        // first-party) client without the admin scope stays base-only.
        $this->actAsOauthClient(['agent'], $this->fakeClient('first-party', confidential: true, firstParty: true));

        $body = $this->oauthProbe()->assertOk()->json();

        $this->assertFalse($body['agent.is_admin']);
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
