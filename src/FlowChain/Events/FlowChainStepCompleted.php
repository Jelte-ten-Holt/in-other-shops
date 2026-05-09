<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class FlowChainStepCompleted
{
    use Dispatchable;

    public function __construct(
        public string $flowName,
        public string $stepClass,
        public float $durationMs,
    ) {}
}
