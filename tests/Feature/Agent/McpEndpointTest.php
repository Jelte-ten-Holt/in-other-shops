<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class McpEndpointTest extends TestCase
{
    private const string BEARER = 'test-bearer-abc123';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('agent.auth.bearer_token', self::BEARER);
    }

    #[Test]
    public function it_401s_when_no_bearer_is_sent(): void
    {
        $this->postJson('/mcp', $this->toolsListPayload())
            ->assertStatus(401);
    }

    #[Test]
    public function it_401s_when_bearer_does_not_match(): void
    {
        $this->postJson('/mcp', $this->toolsListPayload(), [
            'Authorization' => 'Bearer wrong-token',
        ])->assertStatus(401);
    }

    #[Test]
    public function it_401s_when_bearer_is_empty_in_config(): void
    {
        config()->set('agent.auth.bearer_token', '');

        $this->postJson('/mcp', $this->toolsListPayload(), [
            'Authorization' => 'Bearer '.self::BEARER,
        ])->assertStatus(401);
    }

    #[Test]
    public function it_returns_the_registered_tools_on_tools_list(): void
    {
        $response = $this->postJson('/mcp', $this->toolsListPayload(), [
            'Authorization' => 'Bearer '.self::BEARER,
        ])->assertStatus(200);

        $body = $response->json();

        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertSame(1, $body['id']);
        $this->assertArrayHasKey('tools', $body['result']);

        $toolNames = array_column($body['result']['tools'], 'name');
        $this->assertContains('ping', $toolNames);
    }

    /** @return array<string, mixed> */
    private function toolsListPayload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ];
    }
}
