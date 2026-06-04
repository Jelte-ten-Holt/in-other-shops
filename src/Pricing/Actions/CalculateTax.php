<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

/**
 * Adds tax ON TOP of a net amount (exclusive / add-on-top model).
 *
 * EXCLUSIVE-ONLY — do NOT use this on a gross (tax-inclusive) price. The live
 * gross-inclusive checkout path extracts the embedded tax with
 * {@see CalculateIncludedTax} instead; feeding a gross amount here computes VAT
 * on top of an amount that already contains VAT (the classic gross-treated-as-net
 * over-charge). Kept for the exclusive/B2B seam, mirroring the `TaxMode::Exclusive`
 * branch that throws in `CalculateTotal`.
 */
final class CalculateTax
{
    public function __invoke(int $amount, int $rateInBasisPoints): int
    {
        return (int) round($amount * $rateInBasisPoints / 10000);
    }
}
