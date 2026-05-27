<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Unit\FlowChain;

use InOtherShops\FlowChain\AbstractFlowStep;
use InOtherShops\FlowChain\ChainContractValidator;
use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\FlowChain\Exceptions\FlowChainContractViolation;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ChainContractValidatorTest extends TestCase
{
    #[Test]
    public function chain_with_matching_produces_and_consumes_validates(): void
    {
        $validator = new ChainContractValidator;

        $validator->validate(
            chainName: 'test',
            stepClasses: [ProducesCartItem::class, ConsumesCartItem::class],
        );

        // No exception = pass. Explicit assertion to satisfy phpunit.
        $this->assertTrue(true);
    }

    #[Test]
    public function missing_upstream_producer_throws_with_step_and_field(): void
    {
        $validator = new ChainContractValidator;

        try {
            $validator->validate(
                chainName: 'broken',
                stepClasses: [ConsumesCartItem::class],
            );
            $this->fail('Expected FlowChainContractViolation.');
        } catch (FlowChainContractViolation $e) {
            $this->assertStringContainsString("Chain 'broken'", $e->getMessage());
            $this->assertStringContainsString(ConsumesCartItem::class, $e->getMessage());
            $this->assertStringContainsString("'cartItem'", $e->getMessage());
            $this->assertStringContainsString("'CartItem'", $e->getMessage());
        }
    }

    #[Test]
    public function type_mismatch_throws_naming_both_types_and_producer(): void
    {
        $validator = new ChainContractValidator;

        try {
            $validator->validate(
                chainName: 'mismatch',
                stepClasses: [ProducesNullableCartItem::class, ConsumesCartItem::class],
            );
            $this->fail('Expected FlowChainContractViolation.');
        } catch (FlowChainContractViolation $e) {
            $this->assertStringContainsString("'CartItem'", $e->getMessage());
            $this->assertStringContainsString("'?CartItem'", $e->getMessage());
            $this->assertStringContainsString(ProducesNullableCartItem::class, $e->getMessage());
        }
    }

    #[Test]
    public function initial_payload_shape_satisfies_first_step_inputs(): void
    {
        $validator = new ChainContractValidator;

        $validator->validate(
            chainName: 'with-initial',
            stepClasses: [ConsumesCart::class],
            initialPayloadShape: ['cart' => 'Cart'],
        );

        $this->assertTrue(true);
    }

    #[Test]
    public function missing_initial_field_reports_initial_payload_as_the_gap(): void
    {
        $validator = new ChainContractValidator;

        try {
            $validator->validate(
                chainName: 'no-cart',
                stepClasses: [ConsumesCart::class],
            );
            $this->fail('Expected FlowChainContractViolation.');
        } catch (FlowChainContractViolation $e) {
            $this->assertStringContainsString("'cart'", $e->getMessage());
        }
    }

    #[Test]
    public function later_step_can_consume_outputs_of_any_earlier_step(): void
    {
        // Not just the immediately-prior step — accumulated payload state.
        $validator = new ChainContractValidator;

        $validator->validate(
            chainName: 'accumulated',
            stepClasses: [
                ProducesCart::class,
                ProducesCartItem::class,
                ConsumesCartAndCartItem::class,
            ],
        );

        $this->assertTrue(true);
    }

    #[Test]
    public function additive_change_in_upstream_does_not_break_downstream(): void
    {
        // Upstream produces {cart, extraField}; downstream only needs {cart}.
        // The extra field is invisible to downstream — validation passes.
        $validator = new ChainContractValidator;

        $validator->validate(
            chainName: 'additive',
            stepClasses: [ProducesCartAndExtra::class, ConsumesCart::class],
        );

        $this->assertTrue(true);
    }
}

final class ProducesCartItem extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function producedOutputs(): array
    {
        return ['cartItem' => 'CartItem'];
    }
}

final class ProducesNullableCartItem extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function producedOutputs(): array
    {
        return ['cartItem' => '?CartItem'];
    }
}

final class ConsumesCartItem extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function expectedInputs(): array
    {
        return ['cartItem' => 'CartItem'];
    }
}

final class ProducesCart extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function producedOutputs(): array
    {
        return ['cart' => 'Cart'];
    }
}

final class ProducesCartAndExtra extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function producedOutputs(): array
    {
        return ['cart' => 'Cart', 'extraField' => 'string'];
    }
}

final class ConsumesCart extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function expectedInputs(): array
    {
        return ['cart' => 'Cart'];
    }
}

final class ConsumesCartAndCartItem extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function expectedInputs(): array
    {
        return ['cart' => 'Cart', 'cartItem' => 'CartItem'];
    }
}
