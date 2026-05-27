<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain;

use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\FlowChain\Contracts\FlowStep;

/**
 * Default home for FlowStep implementations. Provides empty/version-1
 * defaults so trivial steps don't need to repeat boilerplate.
 *
 * Per package convention (CLAUDE.md "Plugin registries ship as a trio"),
 * concrete steps should extend this abstract rather than implement the
 * FlowStep interface directly.
 *
 * # Type strings (free-form, V1)
 *
 * expectedInputs()/producedOutputs() return `field-name => type` where
 * `type` is a free-form PHP type string. Conventions:
 *
 *   - Scalars: 'int', 'string', 'bool', 'float'
 *   - Nullable: prefix with '?', e.g. '?int', '?\Carbon\CarbonImmutable'
 *   - Classes: fully-qualified name, e.g. 'InOtherShops\Commerce\Cart\Models\Cart'
 *   - Arrays: 'array' (the contract validator does not introspect element types)
 *
 * Type strings feed a hash that detects shape changes. They are NOT
 * type-checked at runtime — they're a declaration, not an enforcement.
 *
 * TODO: upgrade type capture to a reflection-based mechanism once free-form
 * strings prove to be a real footgun (typos producing false hash mismatches).
 * Tracked as "Outstanding" in the FlowChain README.
 */
abstract class AbstractFlowStep implements FlowStep
{
    abstract public function handle(FlowPayload $payload): void;

    /**
     * Default: no upstream dependencies. Override on steps that read payload
     * fields produced by earlier steps in the chain.
     *
     * @return array<string, string>
     */
    public static function expectedInputs(): array
    {
        return [];
    }

    /**
     * Default: no payload writes. Override on steps that populate fields
     * downstream steps will read.
     *
     * @return array<string, string>
     */
    public static function producedOutputs(): array
    {
        return [];
    }

    /**
     * Default: version 1. Bump when this step's BEHAVIOR changes in a way
     * downstream needs to know about — e.g. you flip a sign convention, or
     * change the interpretation of a field while keeping its shape.
     *
     * The hash on producedOutputs() catches structural changes mechanically;
     * this constant catches the semantic changes the hash can't see.
     */
    public static function version(): int
    {
        return 1;
    }
}
