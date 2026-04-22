<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AuthorizationServerMetadataTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('agent.auth.oauth.enabled', true);
        $app['config']->set('agent.canonical_url', 'https://agent.example.test/mcp');
    }

    #[Test]
    public function it_advertises_the_core_oauth_endpoints_and_capabilities(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertStatus(200)
            ->assertJson([
                'issuer' => 'https://agent.example.test',
                'authorization_endpoint' => 'https://agent.example.test/oauth/authorize',
                'token_endpoint' => 'https://agent.example.test/oauth/token',
                'registration_endpoint' => 'https://agent.example.test/oauth/register',
                'scopes_supported' => ['agent'],
                'response_types_supported' => ['code'],
                'grant_types_supported' => ['authorization_code', 'refresh_token'],
                'code_challenge_methods_supported' => ['S256'],
            ])
            ->assertJsonPath('token_endpoint_auth_methods_supported', [
                'client_secret_basic',
                'client_secret_post',
                'none',
            ]);
    }

    #[Test]
    public function it_omits_the_registration_endpoint_when_dcr_is_disabled(): void
    {
        config()->set('agent.auth.oauth.dcr.enabled', false);

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertStatus(200)
            ->assertJsonMissing(['registration_endpoint' => 'https://agent.example.test/oauth/register']);
    }
}
