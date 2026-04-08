<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Events;

use InOtherShops\FlowChain\Contracts\FlowPayload;

final readonly class FlowChainStarted
{
    public function __construct(
        public string $flowName,
        public FlowPayload $payload,
    ) {}
}
