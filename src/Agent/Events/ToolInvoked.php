<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Events;

use InOtherShops\Agent\DTOs\ToolInvocation;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ToolInvoked
{
    use Dispatchable;

    public function __construct(
        public ToolInvocation $invocation,
    ) {}
}
