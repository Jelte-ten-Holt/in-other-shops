<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class FlowChainStepFailed
{
    use Dispatchable;

    public function __construct(
        public string $flowName,
        public string $stepClass,
        public \Throwable $exception,
    ) {}
}
