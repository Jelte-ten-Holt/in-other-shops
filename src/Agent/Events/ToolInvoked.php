<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Events;

use InOtherShops\Agent\DTOs\ToolInvocation;

final class ToolInvoked
{
    public function __construct(
        public readonly ToolInvocation $invocation,
    ) {}
}
