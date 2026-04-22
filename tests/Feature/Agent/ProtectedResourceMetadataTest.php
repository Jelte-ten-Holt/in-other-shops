<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ProtectedResourceMetadataTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('agent.auth.oauth.enabled', true);
        $app['config']->set('agent.canonical_url', 'https://agent.example.test/mcp');
    }

    #[Test]
    public function it_advertises_the_resource_and_authorization_server(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')
            ->assertStatus(200)
            ->assertJson([
                'resource' => 'https://agent.example.test/mcp',
                'authorization_servers' => ['https://agent.example.test'],
                'scopes_supported' => ['agent'],
                'bearer_methods_supported' => ['header'],
            ]);
    }

    #[Test]
    public function it_respects_a_custom_scope_name(): void
    {
        config()->set('agent.auth.oauth.scope', 'mcp.agent');

        $this->getJson('/.well-known/oauth-protected-resource')
            ->assertStatus(200)
            ->assertJsonPath('scopes_supported.0', 'mcp.agent');
    }

    #[Test]
    public function it_falls_back_to_the_app_url_when_canonical_url_is_blank(): void
    {
        config()->set('agent.canonical_url', null);

        $this->getJson('/.well-known/oauth-protected-resource')
            ->assertStatus(200)
            ->assertJsonPath('resource', 'http://localhost/mcp')
            ->assertJsonPath('authorization_servers.0', 'http://localhost');
    }
}
