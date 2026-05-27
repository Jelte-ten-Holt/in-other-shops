<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain;

use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\FlowChain\Contracts\FlowStep;
use InOtherShops\FlowChain\DTOs\FlowChainResult;

/**
 * Base class for chains that consumers can publish into their own project
 * and modify. A publishable chain declares its name, domain (for grouping
 * in `app/Project/FlowChains/{Domain}/`), step list, and initial-payload
 * shape — declaratively, so the registry can introspect without running
 * the chain.
 *
 * The package ships subclasses; consumers run `flowchain:publish` to copy
 * a chain into `app/Project/FlowChains/{Domain}/{ChainName}.php`, then
 * extend the package's class and override the methods they care about
 * (typically `steps()` to insert/remove/reorder).
 *
 * Chains that DON'T need publishing can keep using FlowChainBuilder
 * directly — this abstract is only for the publishable subset.
 *
 * # Conditional steps
 *
 * V1 supports unconditional steps only via `steps()`. For conditional
 * steps, override `configure()` to use the full builder API — but note
 * that conditional steps' contributions to the chain's accumulated payload
 * shape are still treated as if they will run for validation purposes
 * (see ChainContractValidator).
 */
abstract class PublishableFlowChain
{
    /**
     * Stable identifier — used in `app/Project/FlowChains/{Domain}/{ChainName}.php`
     * and as the chain's runtime name. PascalCase, no suffix (don't add
     * "Chain" — the file/class already implies it).
     */
    abstract public static function chainName(): string;

    /**
     * Domain grouping for publish location. PascalCase. Examples: 'Cart',
     * 'Checkout', 'Fulfillment'. The published file goes to
     * `app/Project/FlowChains/{domain}/{ChainName}.php`.
     */
    abstract public static function domain(): string;

    /**
     * Payload fields populated by the payload constructor — i.e. what the
     * caller supplies when invoking the chain. Used by ChainContractValidator
     * as the "starting state" before any step runs.
     *
     * @return array<string, string>  field-name => type
     */
    abstract public static function initialPayloadShape(): array;

    /**
     * Ordered list of step classes the chain runs. Override in published
     * copies to insert/remove/reorder.
     *
     * @return list<class-string<FlowStep>>
     */
    abstract public static function steps(): array;

    /**
     * Whether the chain wraps its execution in a DB transaction.
     * Override to true on chains that must roll back on step failure.
     */
    public static function useTransaction(): bool
    {
        return false;
    }

    /**
     * Build the underlying FlowChain. Default uses `steps()` + `useTransaction()`;
     * override entirely if the chain needs conditional steps or builder features
     * beyond a flat unconditional list.
     */
    public function build(): FlowChain
    {
        $builder = FlowChain::make()->name(static::chainName());

        if (static::useTransaction()) {
            $builder->wrapInTransaction();
        }

        foreach (static::steps() as $stepClass) {
            $builder->step($stepClass);
        }

        return $builder->build();
    }

    public function run(FlowPayload $payload): FlowChainResult
    {
        return $this->build()->run($payload);
    }
}
