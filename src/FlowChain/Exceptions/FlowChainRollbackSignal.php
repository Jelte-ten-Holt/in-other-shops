<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Exceptions;

use InOtherShops\FlowChain\DTOs\FlowChainResult;

/**
 * Internal: thrown inside the transaction closure when a step fails so that
 * Laravel's DB::transaction rolls back pending writes. Caught immediately
 * outside the closure; never leaks to callers.
 */
final class FlowChainRollbackSignal extends \RuntimeException
{
    public function __construct(public readonly FlowChainResult $result)
    {
        parent::__construct('FlowChain step failed — rolling back transaction.');
    }
}
