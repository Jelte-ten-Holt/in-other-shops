<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Events;

use InOtherShops\Variants\Models\Variant;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class VariantCreated
{
    use Dispatchable;

    public function __construct(
        public Variant $variant,
    ) {}
}
