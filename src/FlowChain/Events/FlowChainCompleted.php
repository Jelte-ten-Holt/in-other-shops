<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Events;

use InOtherShops\FlowChain\DTOs\FlowChainResult;

final readonly class FlowChainCompleted
{
    public function __construct(
        public string $flowName,
        public FlowChainResult $result,
    ) {}
}
