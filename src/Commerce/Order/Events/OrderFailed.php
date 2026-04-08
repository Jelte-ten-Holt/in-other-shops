<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class OrderFailed
{
    use Dispatchable;

    public function __construct(
        public string $reason,
        public ?string $failedStep,
    ) {}
}
