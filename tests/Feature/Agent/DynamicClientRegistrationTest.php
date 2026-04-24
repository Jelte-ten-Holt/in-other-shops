<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Support\Facades\Event;
use InOtherShops\Agent\Events\DynamicClientRegistered;
use InOtherShops\Tests\TestCase;
use Laravel\Passport\Client;
use Laravel\Passport\PassportServiceProvider;
use PHPUnit\Framework\Attributes\Test;

final class DynamicClientRegistrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('agent.auth.oauth.enabled', true);
        $app['config']->set('agent.canonical_url', 'https://agent.example.test/mcp');

        $app['config']->set('auth.guards.api', ['driver' => 'passport', 'provider' => 'users']);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => \Illuminate\Foundation\Auth\User::class,
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            PassportServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../../../vendor/laravel/passport/database/migrations');
    }

    #[Test]
    public function it_registers_a_confidential_client_for_a_valid_request(): void
    {
        Event::fake([DynamicClientRegistered::class]);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Co-work connector',
            'redirect_uris' => ['https://claude.ai/callback'],
            'token_endpoint_auth_method' => 'client_secret_basic',
        ])->assertStatus(201);

        $response->assertJsonStructure([
            'client_id',
            'client_secret',
            'client_id_issued_at',
            'client_secret_expires_at',
            'client_name',
            'redirect_uris',
            'grant_types',
            'response_types',
            'scope',
            'token_endpoint_auth_method',
        ]);

        $response->assertJson([
            'client_name' => 'Co-work connector',
            'redirect_uris' => ['https://claude.ai/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => 'agent',
            'token_endpoint_auth_method' => 'client_secret_basic',
            'client_secret_expires_at' => 0,
        ]);

        $this->assertSame(1, Client::query()->count());
        $client = Client::query()->first();
        $this->assertSame('Co-work connector', $client->name);
        $this->assertNotNull($client->secret);
        $this->assertSame(['https://claude.ai/callback'], $client->redirect_uris);

        Event::assertDispatched(DynamicClientRegistered::class, function (DynamicClientRegistered $event) use ($client): bool {
            return $event->clientId === (string) $client->getKey()
                && $event->clientName === 'Co-work connector'
                && $event->isConfidential === true;
        });
    }

    #[Test]
    public function it_registers_a_public_client_when_auth_method_is_none(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Public CLI',
            'redirect_uris' => ['http://127.0.0.1/callback'],
            'token_endpoint_auth_method' => 'none',
        ])->assertStatus(201);

        $response->assertJsonMissing(['client_secret']);
        $response->assertJson([
            'token_endpoint_auth_method' => 'none',
        ]);

        $client = Client::query()->first();
        $this->assertNull($client->secret);
    }

    #[Test]
    public function it_returns_invalid_redirect_uri_when_redirect_uris_is_missing(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Bad client',
        ])->assertStatus(400)
            ->assertJson([
                'error' => 'invalid_redirect_uri',
            ]);

        $this->assertSame(0, Client::query()->count());
    }

    #[Test]
    public function it_returns_invalid_redirect_uri_when_a_uri_is_not_a_url(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Bad client',
            'redirect_uris' => ['not a url'],
        ])->assertStatus(400)
            ->assertJson([
                'error' => 'invalid_redirect_uri',
            ]);
    }

    #[Test]
    public function it_returns_invalid_client_metadata_for_unsupported_grant_type(): void
    {
        $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://claude.ai/callback'],
            'grant_types' => ['password'],
        ])->assertStatus(400)
            ->assertJson([
                'error' => 'invalid_client_metadata',
            ]);
    }

    #[Test]
    public function it_returns_invalid_client_metadata_for_unsupported_scope(): void
    {
        $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://claude.ai/callback'],
            'scope' => 'admin',
        ])->assertStatus(400)
            ->assertJson([
                'error' => 'invalid_client_metadata',
            ]);
    }

    #[Test]
    public function it_returns_invalid_client_metadata_for_unsupported_auth_method(): void
    {
        $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://claude.ai/callback'],
            'token_endpoint_auth_method' => 'private_key_jwt',
        ])->assertStatus(400)
            ->assertJson([
                'error' => 'invalid_client_metadata',
            ]);
    }

    #[Test]
    public function it_rejects_registration_without_the_initial_access_token_when_one_is_required(): void
    {
        config()->set('agent.auth.oauth.dcr.initial_access_token', 'iat-secret');

        $this->postJson('/oauth/register', [
            'client_name' => 'Unauthorized caller',
            'redirect_uris' => ['https://claude.ai/callback'],
        ])->assertStatus(401)
            ->assertJson(['error' => 'invalid_token'])
            ->assertHeader('WWW-Authenticate', 'Bearer');

        $this->assertSame(0, Client::query()->count());
    }

    #[Test]
    public function it_rejects_registration_when_the_initial_access_token_does_not_match(): void
    {
        config()->set('agent.auth.oauth.dcr.initial_access_token', 'iat-secret');

        $this->postJson('/oauth/register', [
            'client_name' => 'Wrong token',
            'redirect_uris' => ['https://claude.ai/callback'],
        ], [
            'Authorization' => 'Bearer not-the-real-token',
        ])->assertStatus(401)
            ->assertJson(['error' => 'invalid_token']);

        $this->assertSame(0, Client::query()->count());
    }

    #[Test]
    public function it_registers_when_the_initial_access_token_matches(): void
    {
        config()->set('agent.auth.oauth.dcr.initial_access_token', 'iat-secret');

        $this->postJson('/oauth/register', [
            'client_name' => 'Authorized caller',
            'redirect_uris' => ['https://claude.ai/callback'],
        ], [
            'Authorization' => 'Bearer iat-secret',
        ])->assertStatus(201);

        $this->assertSame(1, Client::query()->count());
    }

    #[Test]
    public function it_rejects_registration_when_the_client_cap_is_reached(): void
    {
        config()->set('agent.auth.oauth.dcr.max_clients', 1);

        $this->postJson('/oauth/register', [
            'client_name' => 'First',
            'redirect_uris' => ['https://claude.ai/callback'],
        ])->assertStatus(201);

        $this->postJson('/oauth/register', [
            'client_name' => 'Second',
            'redirect_uris' => ['https://claude.ai/callback'],
        ])->assertStatus(429)
            ->assertJson(['error' => 'too_many_clients']);

        $this->assertSame(1, Client::query()->count());
    }
}
