<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

/**
 * Tax already contained inside a gross (tax-inclusive) amount.
 *
 * net = round(gross * 10000 / (10000 + rate)); tax = gross − net. A 0 rate
 * yields 0 tax (gross == net). Counterpart to {@see CalculateTax}, which adds
 * tax on top of a net amount.
 */
final class CalculateIncludedTax
{
    public function __invoke(int $grossAmount, int $rateInBasisPoints): int
    {
        $net = (int) round($grossAmount * 10000 / (10000 + $rateInBasisPoints));

        return $grossAmount - $net;
    }
}
