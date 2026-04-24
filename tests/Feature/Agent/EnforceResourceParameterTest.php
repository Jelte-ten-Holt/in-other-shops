<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Support\Facades\Route;
use InOtherShops\Agent\Http\Middleware\EnforceResourceParameter;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EnforceResourceParameterTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('/test/resource-echo', fn () => response()->json(['ok' => true]))
            ->middleware(EnforceResourceParameter::class);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('agent.auth.oauth.enabled', true);
        $app['config']->set('agent.canonical_url', 'https://agent.example.test/mcp');
    }

    #[Test]
    public function it_allows_requests_without_a_resource_parameter(): void
    {
        $this->postJson('/test/resource-echo', [])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    #[Test]
    public function it_allows_the_exact_canonical_url(): void
    {
        $this->postJson('/test/resource-echo', ['resource' => 'https://agent.example.test/mcp'])
            ->assertStatus(200);
    }

    #[Test]
    public function it_tolerates_a_trailing_slash_on_the_resource_value(): void
    {
        $this->postJson('/test/resource-echo', ['resource' => 'https://agent.example.test/mcp/'])
            ->assertStatus(200);
    }

    #[Test]
    public function it_rejects_a_mismatched_resource(): void
    {
        $this->postJson('/test/resource-echo', ['resource' => 'https://evil.example.test/mcp'])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_target']);
    }

    #[Test]
    public function it_rejects_when_any_resource_in_an_array_is_mismatched(): void
    {
        $this->postJson('/test/resource-echo', [
            'resource' => [
                'https://agent.example.test/mcp',
                'https://evil.example.test/mcp',
            ],
        ])->assertStatus(400)
            ->assertJson(['error' => 'invalid_target']);
    }

    #[Test]
    public function it_is_a_noop_when_oauth_is_disabled(): void
    {
        config()->set('agent.auth.oauth.enabled', false);

        $this->postJson('/test/resource-echo', ['resource' => 'https://evil.example.test/mcp'])
            ->assertStatus(200);
    }

    #[Test]
    public function it_rejects_a_missing_resource_when_require_resource_is_on(): void
    {
        config()->set('agent.auth.oauth.require_resource', true);

        $this->postJson('/test/resource-echo', [])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_target']);
    }

    #[Test]
    public function it_still_allows_the_exact_resource_when_require_resource_is_on(): void
    {
        config()->set('agent.auth.oauth.require_resource', true);

        $this->postJson('/test/resource-echo', ['resource' => 'https://agent.example.test/mcp'])
            ->assertStatus(200);
    }
}
