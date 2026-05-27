<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Fixtures\FlowChain\Published\Cart;

use InOtherShops\Tests\Fixtures\FlowChain\Package\Cart\SampleChain as PackageSampleChain;

/**
 * Stand-in for a consumer's published copy of SampleChain. Extends the
 * package class so chainName() / domain() / initialPayloadShape() stay
 * consistent; overrides steps() to simulate consumer modification.
 */
final class SampleChain extends PackageSampleChain
{
    public static function steps(): array
    {
        return [];
    }
}
