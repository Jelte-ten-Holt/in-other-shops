<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

final class CalculateTax
{
    public function __invoke(int $amount, int $rateInBasisPoints = 2100): int
    {
        return (int) round($amount * $rateInBasisPoints / 10000);
    }
}
