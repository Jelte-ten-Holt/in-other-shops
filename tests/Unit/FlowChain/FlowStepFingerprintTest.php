<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Unit\FlowChain;

use InOtherShops\FlowChain\AbstractFlowStep;
use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\FlowChain\FlowStepFingerprint;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FlowStepFingerprintTest extends TestCase
{
    #[Test]
    public function fingerprint_is_deterministic_for_the_same_step_shape(): void
    {
        $first = FlowStepFingerprint::ofStep(StepWithShape::class);
        $second = FlowStepFingerprint::ofStep(StepWithShape::class);

        $this->assertTrue($first->equals($second));
    }

    #[Test]
    public function key_order_does_not_affect_hash(): void
    {
        $sorted = FlowStepFingerprint::ofStep(StepWithSortedFields::class);
        $reversed = FlowStepFingerprint::ofStep(StepWithReversedFields::class);

        $this->assertSame($sorted->hash, $reversed->hash);
    }

    #[Test]
    public function adding_a_field_changes_the_hash(): void
    {
        $a = FlowStepFingerprint::ofStep(StepWithShape::class);
        $b = FlowStepFingerprint::ofStep(StepWithExtraOutput::class);

        $this->assertNotSame($a->hash, $b->hash);
    }

    #[Test]
    public function changing_a_type_changes_the_hash(): void
    {
        $a = FlowStepFingerprint::ofStep(StepWithShape::class);
        $b = FlowStepFingerprint::ofStep(StepWithShapeButDifferentType::class);

        $this->assertNotSame($a->hash, $b->hash);
    }

    #[Test]
    public function version_bump_changes_the_fingerprint_but_not_the_hash(): void
    {
        $a = FlowStepFingerprint::ofStep(StepWithShape::class);
        $b = FlowStepFingerprint::ofStep(StepWithShapeAtV2::class);

        $this->assertSame($a->hash, $b->hash, 'Hash should be identical when shape is unchanged.');
        $this->assertFalse($a->equals($b), 'Equality must also check version.');
        $this->assertSame(2, $b->version);
    }

    #[Test]
    public function string_form_is_short_hash_and_version(): void
    {
        $fingerprint = new FlowStepFingerprint(hash: str_repeat('a', 64), version: 3);

        $this->assertSame('aaaaaaaaaaaa@v3', (string) $fingerprint);
    }
}

final class StepWithShape extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function expectedInputs(): array
    {
        return ['cart' => 'Cart'];
    }

    public static function producedOutputs(): array
    {
        return ['cartItem' => 'CartItem'];
    }
}

final class StepWithSortedFields extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function producedOutputs(): array
    {
        return ['alpha' => 'A', 'beta' => 'B', 'gamma' => 'C'];
    }
}

final class StepWithReversedFields extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function producedOutputs(): array
    {
        return ['gamma' => 'C', 'beta' => 'B', 'alpha' => 'A'];
    }
}

final class StepWithExtraOutput extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function expectedInputs(): array
    {
        return ['cart' => 'Cart'];
    }

    public static function producedOutputs(): array
    {
        return ['cartItem' => 'CartItem', 'newField' => 'string'];
    }
}

final class StepWithShapeButDifferentType extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function expectedInputs(): array
    {
        return ['cart' => 'Cart'];
    }

    public static function producedOutputs(): array
    {
        return ['cartItem' => '?CartItem'];
    }
}

final class StepWithShapeAtV2 extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void {}

    public static function expectedInputs(): array
    {
        return ['cart' => 'Cart'];
    }

    public static function producedOutputs(): array
    {
        return ['cartItem' => 'CartItem'];
    }

    public static function version(): int
    {
        return 2;
    }
}
