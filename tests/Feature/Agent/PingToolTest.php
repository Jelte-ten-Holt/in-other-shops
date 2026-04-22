<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Support\Facades\Event;
use InOtherShops\Agent\Events\ToolInvoked;
use InOtherShops\Agent\Tools\Ping;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PingToolTest extends TestCase
{
    #[Test]
    public function it_returns_pong_and_a_timestamp(): void
    {
        $result = (new Ping)([]);

        $this->assertTrue($result['pong']);
        $this->assertIsString($result['at']);
        $this->assertNull($result['echo']);
    }

    #[Test]
    public function it_echoes_back_when_requested(): void
    {
        $result = (new Ping)(['echo' => 'hello']);

        $this->assertSame('hello', $result['echo']);
    }

    #[Test]
    public function it_dispatches_tool_invoked_through_the_library_execute_path(): void
    {
        Event::fake([ToolInvoked::class]);

        (new Ping)->execute(['echo' => 'hi']);

        Event::assertDispatched(ToolInvoked::class, function (ToolInvoked $event) {
            return $event->invocation->tool === 'ping'
                && $event->invocation->redactedInput === ['echo' => 'hi']
                && $event->invocation->error === null;
        });
    }
}
