<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\AgentTool;
use InOtherShops\Agent\Support\ToolRegistry;
use InOtherShops\Agent\Tools\Ping;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ToolRegistryTest extends TestCase
{
    #[Test]
    public function it_registers_package_tools_with_empty_consumer_config(): void
    {
        config()->set('agent.tools', []);

        $registry = new ToolRegistry($this->app);

        $this->assertNotNull($registry->find('ping'));
        $this->assertNotNull($registry->find('adjust_stock'));
        $this->assertSame(9, $registry->all()->count());
    }

    #[Test]
    public function it_concatenates_consumer_tools_onto_package_defaults(): void
    {
        config()->set('agent.tools', [FakeConsumerTool::class]);

        $registry = new ToolRegistry($this->app);

        $this->assertNotNull($registry->find('ping'), 'Package tools must survive a consumer publishing its own config/agent.php.');
        $this->assertNotNull($registry->find('fake_consumer_tool'));
        $this->assertSame(10, $registry->all()->count());
    }

    #[Test]
    public function classes_returns_package_tools_first_then_consumer_tools(): void
    {
        config()->set('agent.tools', [FakeConsumerTool::class]);

        $classes = (new ToolRegistry($this->app))->classes();

        $this->assertSame(Ping::class, $classes[0]);
        $this->assertSame(FakeConsumerTool::class, end($classes));
    }
}

final class FakeConsumerTool extends AgentTool
{
    public static function identifier(): string
    {
        return 'fake_consumer_tool';
    }

    public static function displayName(): string
    {
        return 'Fake Consumer Tool';
    }

    public function description(): string
    {
        return 'Stand-in consumer tool used only by ToolRegistryTest.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public function __invoke(array $arguments): array
    {
        return ['ok' => true, 'data' => []];
    }
}
