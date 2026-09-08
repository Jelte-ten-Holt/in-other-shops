<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\AgentTool;
use InOtherShops\Agent\Support\ToolRegistry;
use InOtherShops\Agent\Tools\AdjustStock;
use InOtherShops\Agent\Tools\GetRecentProblems;
use InOtherShops\Agent\Tools\Ping;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ToolRegistryTest extends TestCase
{
    #[Test]
    public function it_lists_package_tools_with_empty_consumer_config(): void
    {
        config()->set('agent.tools', []);

        $classes = ToolRegistry::classes();

        $this->assertContains(Ping::class, $classes);
        $this->assertContains(AdjustStock::class, $classes);
        $this->assertContains(GetRecentProblems::class, $classes);
        $this->assertCount(11, $classes);
    }

    #[Test]
    public function it_concatenates_consumer_tools_onto_package_defaults(): void
    {
        config()->set('agent.tools', [FakeConsumerTool::class]);

        $classes = ToolRegistry::classes();

        $this->assertContains(Ping::class, $classes, 'Package tools must survive a consumer publishing its own config/agent.php.');
        $this->assertContains(FakeConsumerTool::class, $classes);
        $this->assertCount(12, $classes);
    }

    #[Test]
    public function classes_returns_package_tools_first_then_consumer_tools(): void
    {
        config()->set('agent.tools', [FakeConsumerTool::class]);

        $classes = ToolRegistry::classes();

        $this->assertSame(Ping::class, $classes[0]);
        $this->assertSame(FakeConsumerTool::class, end($classes));
    }

    #[Test]
    public function listing_the_tools_resolves_nothing_out_of_the_container(): void
    {
        // The registry used to `app->make()` every tool up front, on every
        // request that registered the MCP route. Class names only now.
        $resolved = [];
        $this->app->resolving(function (mixed $object) use (&$resolved): void {
            if ($object instanceof AgentTool) {
                $resolved[] = $object::class;
            }
        });

        ToolRegistry::classes();

        $this->assertSame([], $resolved);
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
