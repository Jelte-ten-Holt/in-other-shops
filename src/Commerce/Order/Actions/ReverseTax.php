<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use InOtherShops\Pricing\DTOs\TaxBreakdownLine;
use InOtherShops\Pricing\Support\LargestRemainderAllocator;

/**
 * Computes the per-bracket VAT to reverse for ONE refund, given the order's
 * originally-charged per-bracket tax_summary and the cumulative amount refunded
 * so far (including this refund).
 *
 * Why cumulative-anchored instead of "proportional on each refund amount":
 * rounding each refund independently lets a sequence of partial refunds drift
 * below the originally-charged tax (e.g. 587+587+586 of a €17.60 order reverses
 * only 207c of 210c charged — 3c of VAT silently never reverses). Anchoring each
 * computation to the cumulative refunded amount and emitting the DELTA against
 * what prior refunds already reversed makes a full sequence reconcile exactly:
 * at cumulative == originalAmount, every bracket is reversed to its charged tax.
 *
 * Allocation uses the largest-remainder method weighted by each bracket's
 * ORIGINAL tax/base, so a zero-tax (zero-rated / out-of-jurisdiction) bracket can
 * never receive a borrowed cent, and no bracket is ever reversed beyond what it
 * was charged. All amounts are integer cents.
 */
final class ReverseTax
{
    public function __construct(
        private readonly LargestRemainderAllocator $allocate,
    ) {}

    /**
     * @param  list<TaxBreakdownLine>  $originalBrackets  the order's charged tax_summary
     * @param  int  $originalAmount  the full refundable amount (order total / payment amount)
     * @param  int  $cumulativeRefunded  amount refunded after this refund (>= prior cumulative)
     * @param  array<int, int>  $alreadyReversedTax  rateBps => tax already reversed by prior refunds
     * @param  array<int, int>  $alreadyReversedBase  rateBps => taxable_base already reversed
     * @return list<TaxBreakdownLine>  this refund's per-bracket reversal (delta); brackets with a
     *                                 zero delta are omitted
     */
    public function __invoke(
        array $originalBrackets,
        int $originalAmount,
        int $cumulativeRefunded,
        array $alreadyReversedTax = [],
        array $alreadyReversedBase = [],
    ): array {
        if ($originalBrackets === [] || $originalAmount <= 0 || $cumulativeRefunded <= 0) {
            return [];
        }

        // Cumulative target reversed per bracket, for both the tax and the
        // taxable-base columns, weighted by the original bracket values. Capped
        // at each bracket's original value — never reverse more than was charged.
        $targetTax = ($this->allocate)(
            array_map(fn (TaxBreakdownLine $b): int => $b->tax, $originalBrackets),
            $cumulativeRefunded,
            $originalAmount,
        );

        $targetBase = ($this->allocate)(
            array_map(fn (TaxBreakdownLine $b): int => $b->taxableBase, $originalBrackets),
            $cumulativeRefunded,
            $originalAmount,
        );

        $deltas = [];

        foreach ($originalBrackets as $i => $bracket) {
            $taxDelta = $targetTax[$i] - ($alreadyReversedTax[$bracket->rateBps] ?? 0);
            $baseDelta = $targetBase[$i] - ($alreadyReversedBase[$bracket->rateBps] ?? 0);

            if ($taxDelta === 0 && $baseDelta === 0) {
                continue;
            }

            $deltas[] = new TaxBreakdownLine(
                rateBps: $bracket->rateBps,
                taxableBase: $baseDelta,
                tax: $taxDelta,
            );
        }

        return $deltas;
    }
}
