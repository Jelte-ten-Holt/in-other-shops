<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Support\Facades\Event;
use InOtherShops\Agent\AgentTool;
use InOtherShops\Agent\Events\ToolInvocationFailed;
use InOtherShops\Tests\TestCase;
use InvalidArgumentException;
use OPGG\LaravelMcpServer\Exceptions\Enums\JsonRpcErrorCode;
use OPGG\LaravelMcpServer\Exceptions\JsonRpcErrorException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class AgentToolExecuteTest extends TestCase
{
    #[Test]
    public function it_converts_invalid_argument_exception_to_json_rpc_invalid_params(): void
    {
        Event::fake([ToolInvocationFailed::class]);

        $tool = new class extends AgentTool
        {
            public static function identifier(): string
            {
                return 'shape_thrower';
            }

            public static function displayName(): string
            {
                return 'Shape thrower';
            }

            public function description(): string
            {
                return 'Test tool that throws on shape errors.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function __invoke(array $arguments): array
            {
                throw new InvalidArgumentException('expected shape: foo');
            }
        };

        try {
            $tool->execute([]);
            $this->fail('Expected JsonRpcErrorException was not thrown.');
        } catch (JsonRpcErrorException $e) {
            $this->assertSame(JsonRpcErrorCode::INVALID_PARAMS->value, $e->getJsonRpcErrorCode());
            $this->assertSame('expected shape: foo', $e->getMessage());
        }

        Event::assertDispatched(ToolInvocationFailed::class);
    }

    #[Test]
    public function it_lets_other_throwables_propagate_unchanged(): void
    {
        $tool = new class extends AgentTool
        {
            public static function identifier(): string
            {
                return 'runtime_thrower';
            }

            public static function displayName(): string
            {
                return 'Runtime thrower';
            }

            public function description(): string
            {
                return 'Test tool that throws unexpected runtime errors.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function __invoke(array $arguments): array
            {
                throw new RuntimeException('unexpected');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected');

        $tool->execute([]);
    }
}
