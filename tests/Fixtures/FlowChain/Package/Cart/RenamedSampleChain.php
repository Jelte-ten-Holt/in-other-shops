<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Fixtures\FlowChain\Package\Cart;

use InOtherShops\FlowChain\PublishableFlowChain;

/**
 * Fixture for the case where chainName() does NOT match the source class's
 * short name. AddToCartChain (source class) returns 'AddToCart' from
 * chainName() — verifying the publish command handles this rename was the
 * regression that bit at first real-world use.
 */
class RenamedSampleChain extends PublishableFlowChain
{
    public static function chainName(): string
    {
        return 'Renamed';
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
