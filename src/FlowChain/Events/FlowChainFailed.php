<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use InOtherShops\FlowChain\DTOs\FlowChainResult;

final readonly class FlowChainFailed
{
    use Dispatchable;

    public function __construct(
        public string $flowName,
        public FlowChainResult $result,
    ) {}
}
