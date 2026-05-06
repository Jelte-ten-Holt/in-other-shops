<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Events;

use InOtherShops\Agent\DTOs\ToolInvocation;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ToolInvocationFailed
{
    use Dispatchable;

    public function __construct(
        public ToolInvocation $invocation,
    ) {}
}
