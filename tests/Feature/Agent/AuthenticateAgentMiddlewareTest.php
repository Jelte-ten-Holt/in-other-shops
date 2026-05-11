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
}
