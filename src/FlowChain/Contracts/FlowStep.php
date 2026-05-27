<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Contracts;

interface FlowStep
{
    public function handle(FlowPayload $payload): void;

    /**
     * Payload fields this step expects to find populated when it runs.
     *
     * Used by the chain-contract validator to assert that every required
     * field has an upstream producer. The returned map is `field-name => type`
     * where `type` is a free-form PHP type string (FQNs for classes,
     * `?` prefix for nullable). See AbstractFlowStep::TYPE_CONVENTIONS for
     * the canonical strings.
     *
     * Empty by default — entry steps (which receive their inputs through the
     * payload constructor, not from upstream steps) have no upstream
     * dependencies.
     *
     * @return array<string, string>
     */
    public static function expectedInputs(): array;

    /**
     * Payload fields this step writes when it runs.
     *
     * Same map shape as expectedInputs(). The validator hashes this to detect
     * shape changes; downstream steps that declare these fields in their
     * expectedInputs() will be flagged if the upstream produced shape changes.
     *
     * @return array<string, string>
     */
    public static function producedOutputs(): array;

    /**
     * Semantic version of this step.
     *
     * Bump when the step's BEHAVIOR changes in a way downstream needs to
     * know about (e.g. you now write a quantity as a signed delta rather
     * than an absolute level — same shape, different meaning). The hash
     * mechanism catches shape changes automatically; this constant catches
     * the semantic changes the hash can't see.
     *
     * Between package major versions, producedOutputs() is additive-only.
     * Major version bumps (e.g. 0.x → 1.0) are the only point at which a
     * step may DROP a previously-produced field; signal that intent with a
     * version bump and a CHANGELOG entry so downstream consumers know to
     * audit their published chains.
     */
    public static function version(): int;
}
