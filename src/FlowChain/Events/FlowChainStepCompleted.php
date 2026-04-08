<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Events;

final readonly class FlowChainStepCompleted
{
    public function __construct(
        public string $flowName,
        public string $stepClass,
        public float $durationMs,
    ) {}
}
