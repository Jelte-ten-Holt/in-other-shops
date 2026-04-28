<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Concerns;

trait InteractsWithShippability
{
    public function requiresShipping(): bool
    {
        return true;
    }
}
