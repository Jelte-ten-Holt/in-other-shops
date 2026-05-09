<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use InOtherShops\FlowChain\Contracts\FlowPayload;

final readonly class FlowChainStarted
{
    use Dispatchable;

    public function __construct(
        public string $flowName,
        public FlowPayload $payload,
    ) {}
}
