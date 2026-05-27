<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Fixtures\FlowChain\Package\Cart;

use InOtherShops\FlowChain\PublishableFlowChain;

/**
 * Stand-in for a real package-shipped chain. Used by FlowChainRegistry
 * tests to verify resolve() returns this when no published copy exists.
 */
class SampleChain extends PublishableFlowChain
{
    public static function chainName(): string
    {
        return 'SampleChain';
    }

    public static function domain(): string
    {
        return 'Cart';
    }

    public static function initialPayloadShape(): array
    {
        return [];
    }

    public static function steps(): array
    {
        return [];
    }
}
