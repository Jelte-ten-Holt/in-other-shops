<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\Support\ToolRegistry;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `readOnlyHint` lets an MCP client skip its permission prompt, so a write
 * tool carrying it would be waved through. The list is pinned exactly: marking
 * a tool read-only, or adding a read tool, is a deliberate edit here.
 */
final class ToolAnnotationsTest extends TestCase
{
    #[Test]
    public function exactly_the_package_read_tools_carry_the_read_only_hint(): void
    {
        config()->set('agent.tools', []);

        $marked = [];

        foreach (ToolRegistry::classes() as $class) {
            if ((app($class)->annotations()['readOnlyHint'] ?? false) === true) {
                $marked[] = $class::identifier();
            }
        }

        sort($marked);

        $this->assertSame([
            'browse_catalog',
            'get_recent_problems',
            'get_stock_level',
            'list_categories',
            'list_orders',
            'list_stock_levels',
            'list_tags',
            'ping',
            'show_browsable',
            'show_order',
        ], $marked);
    }
}
