<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Exceptions;

use DomainException;
use InOtherShops\FlowChain\Contracts\FlowStep;
use InOtherShops\FlowChain\FlowStepFingerprint;

/**
 * Thrown by ChainContractValidator when a chain's step list is incompatible
 * — a step's expectedInputs() can't be satisfied by accumulated upstream
 * producedOutputs(), or types mismatch on a field name that IS produced.
 */
final class FlowChainContractViolation extends DomainException
{
    /**
     * @param  class-string<FlowStep>  $stepClass
     */
    public static function missingField(string $chainName, string $stepClass, string $field, string $expectedType): self
    {
        $fingerprint = FlowStepFingerprint::ofStep($stepClass);

        return new self(
            "Chain '{$chainName}': step {$stepClass} ({$fingerprint}) expects payload field "
            ."'{$field}' of type '{$expectedType}', but no upstream step (or initial payload) "
            ."produces it. Either reorder the chain, add an upstream producer, or remove the "
            ."expectedInputs entry."
        );
    }

    /**
     * @param  class-string<FlowStep>  $stepClass
     */
    public static function typeMismatch(string $chainName, string $stepClass, string $field, string $expectedType, string $actualType, string $producerClass): self
    {
        $fingerprint = FlowStepFingerprint::ofStep($stepClass);

        return new self(
            "Chain '{$chainName}': step {$stepClass} ({$fingerprint}) expects payload field "
            ."'{$field}' of type '{$expectedType}', but upstream {$producerClass} writes it as "
            ."'{$actualType}'. Update one side to match — types must agree exactly (including "
            ."nullable '?' prefix)."
        );
    }
}
