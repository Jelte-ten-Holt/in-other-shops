<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Exceptions;

use RuntimeException;

final class StepFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $stepClass,
        \Throwable $previous,
    ) {
        parent::__construct(
            message: "Flow step [{$stepClass}] failed: {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
