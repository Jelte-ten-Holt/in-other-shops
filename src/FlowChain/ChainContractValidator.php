<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain;

use InOtherShops\FlowChain\Contracts\FlowStep;
use InOtherShops\FlowChain\Exceptions\FlowChainContractViolation;

/**
 * Validates a chain's step list at definition time.
 *
 * For each step in order, asserts that every entry in its expectedInputs()
 * map is satisfied by either:
 *   - the initial payload shape (passed in by the caller), or
 *   - the producedOutputs() of some earlier step in the chain.
 *
 * Throws FlowChainContractViolation on the first mismatch — missing field
 * or wrong type on a field that IS produced upstream.
 *
 * # Type comparison
 *
 * Types are compared as strings. Exact match required, including the '?'
 * nullable prefix. This is intentionally strict — adding nullable to a
 * previously-non-nullable field is a contract change that downstream
 * readers need to be aware of (they may not handle nulls).
 *
 * # Conditional steps
 *
 * V1 treats `->when()` steps as if they will run. A conditional step's
 * outputs ARE considered present for downstream validation. This means a
 * downstream unconditional step that depends on a conditional step's
 * output will pass validation but may fail at runtime if the condition
 * evaluates false. Document this gap on conditional outputs; tighten
 * the validator if it becomes a real footgun.
 */
final class ChainContractValidator
{
    /**
     * @param  list<class-string<FlowStep>>  $stepClasses
     * @param  array<string, string>  $initialPayloadShape  field-name => type, populated by the payload constructor
     */
    public function validate(string $chainName, array $stepClasses, array $initialPayloadShape = []): void
    {
        // Tracks every field that could be in the payload by the time the
        // current step runs. Maps field-name => [type, producerClass-or-null].
        $accumulated = [];
        foreach ($initialPayloadShape as $field => $type) {
            $accumulated[$field] = ['type' => $type, 'producer' => null];
        }

        foreach ($stepClasses as $stepClass) {
            $this->validateStep($chainName, $stepClass, $accumulated);

            foreach ($stepClass::producedOutputs() as $field => $type) {
                // Later producers overwrite earlier ones in the accumulated
                // map. Whether that's "right" is a downstream concern — the
                // validator only checks that whatever's CURRENT when a step
                // reads matches what the step expects.
                $accumulated[$field] = ['type' => $type, 'producer' => $stepClass];
            }
        }
    }

    /**
     * @param  class-string<FlowStep>  $stepClass
     * @param  array<string, array{type: string, producer: ?class-string<FlowStep>}>  $accumulated
     */
    private function validateStep(string $chainName, string $stepClass, array $accumulated): void
    {
        foreach ($stepClass::expectedInputs() as $field => $expectedType) {
            if (! array_key_exists($field, $accumulated)) {
                throw FlowChainContractViolation::missingField(
                    chainName: $chainName,
                    stepClass: $stepClass,
                    field: $field,
                    expectedType: $expectedType,
                );
            }

            $producedType = $accumulated[$field]['type'];
            if ($producedType !== $expectedType) {
                throw FlowChainContractViolation::typeMismatch(
                    chainName: $chainName,
                    stepClass: $stepClass,
                    field: $field,
                    expectedType: $expectedType,
                    actualType: $producedType,
                    producerClass: $accumulated[$field]['producer'] ?? '<initial payload>',
                );
            }
        }
    }
}
