<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Events;

final readonly class FlowChainStepFailed
{
    public function __construct(
        public string $flowName,
        public string $stepClass,
        public \Throwable $exception,
    ) {}
}
