<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain;

use InOtherShops\FlowChain\Contracts\FlowStep;

/**
 * Identifies a FlowStep's shape contract.
 *
 * The hash is computed deterministically from the step's declared
 * expectedInputs() and producedOutputs() (sorted by field name so that
 * declaration order doesn't affect the hash). The version is the step's
 * own version() — bumped manually for semantic changes the hash can't see.
 *
 * Two fingerprints are equal when both hash AND version match. The
 * fingerprint exists primarily to communicate "what changed" in
 * error messages and changelog entries; the chain validator does its own
 * subset checking against accumulated payload state rather than direct
 * fingerprint comparison.
 */
final readonly class FlowStepFingerprint
{
    public function __construct(
        public string $hash,
        public int $version,
    ) {}

    /**
     * @param  class-string<FlowStep>  $stepClass
     */
    public static function ofStep(string $stepClass): self
    {
        $inputs = $stepClass::expectedInputs();
        $outputs = $stepClass::producedOutputs();
        ksort($inputs);
        ksort($outputs);

        $shape = [
            'inputs' => $inputs,
            'outputs' => $outputs,
        ];

        return new self(
            hash: hash('sha256', (string) json_encode($shape, JSON_THROW_ON_ERROR)),
            version: $stepClass::version(),
        );
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash && $this->version === $other->version;
    }

    /**
     * Short human-readable form for error messages and logs.
     * Example: "a1b2c3d4e5f6@v2"
     */
    public function __toString(): string
    {
        return substr($this->hash, 0, 12).'@v'.$this->version;
    }
}
