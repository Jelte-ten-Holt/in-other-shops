<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

final class CalculateShippingCost
{
    public function __invoke(): int
    {
        return (int) config('shipping.flat_rate', 0);
    }
}
