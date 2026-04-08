<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\DTOs;

use InOtherShops\FlowChain\Enums\FlowChainStepStatus;

final readonly class FlowChainStepResult
{
    public function __construct(
        public string $stepClass,
        public FlowChainStepStatus $status,
        public float $durationMs = 0.0,
        public ?string $error = null,
    ) {}
}
