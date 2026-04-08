<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\DTOs;

use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\FlowChain\Enums\FlowChainStatus;

final readonly class FlowChainResult
{
    /**
     * @param  array<int, FlowChainStepResult>  $steps
     */
    public function __construct(
        public FlowChainStatus $status,
        public FlowPayload $payload,
        public array $steps,
        public ?string $failedStep = null,
        public ?\Throwable $exception = null,
        public float $durationMs = 0.0,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === FlowChainStatus::Completed;
    }

    public function failed(): bool
    {
        return $this->status === FlowChainStatus::Failed;
    }
}
