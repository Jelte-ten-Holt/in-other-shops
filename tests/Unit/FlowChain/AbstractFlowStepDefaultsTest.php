<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Unit\FlowChain;

use InOtherShops\FlowChain\AbstractFlowStep;
use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AbstractFlowStepDefaultsTest extends TestCase
{
    #[Test]
    public function default_expected_inputs_is_empty(): void
    {
        $this->assertSame([], TrivialStep::expectedInputs());
    }

    #[Test]
    public function default_produced_outputs_is_empty(): void
    {
        $this->assertSame([], TrivialStep::producedOutputs());
    }

    #[Test]
    public function default_version_is_one(): void
    {
        $this->assertSame(1, TrivialStep::version());
    }

    #[Test]
    public function subclasses_override_inputs_outputs_and_version(): void
    {
        $this->assertSame(['cart' => 'InOtherShops\\Commerce\\Cart\\Models\\Cart'], OverridingStep::expectedInputs());
        $this->assertSame(['cartItem' => 'InOtherShops\\Commerce\\Cart\\Models\\CartItem'], OverridingStep::producedOutputs());
        $this->assertSame(3, OverridingStep::version());
    }
}

final class TrivialStep extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}
}

final class OverridingStep extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function expectedInputs(): array
    {
        return ['cart' => 'InOtherShops\\Commerce\\Cart\\Models\\Cart'];
    }

    public static function producedOutputs(): array
    {
        return ['cartItem' => 'InOtherShops\\Commerce\\Cart\\Models\\CartItem'];
    }

    public static function version(): int
    {
        return 3;
    }
}
