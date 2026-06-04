<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use InOtherShops\Pricing\DTOs\TaxBreakdownLine;

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
        // taxable-base columns, weighted by the original bracket values.
        $targetTax = $this->allocate(
            array_map(fn (TaxBreakdownLine $b): int => $b->tax, $originalBrackets),
            $cumulativeRefunded,
            $originalAmount,
        );

        $targetBase = $this->allocate(
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

    /**
     * Largest-remainder allocation of `round(sum(orig) × num / den)` across the
     * brackets, weighted by each bracket's original value. Pure integer math.
     *
     * @param  list<int>  $orig
     * @return list<int>  the cumulative target per bracket (same order as $orig)
     */
    private function allocate(array $orig, int $num, int $den): array
    {
        $total = array_sum($orig);

        if ($total <= 0) {
            return array_fill(0, count($orig), 0);
        }

        // round-half-up of total × num / den, integer-only
        $totalTarget = intdiv(2 * $total * $num + $den, 2 * $den);

        $floors = [];
        $remainders = [];

        foreach ($orig as $i => $value) {
            $scaled = $value * $num;
            $floors[$i] = intdiv($scaled, $den);
            $remainders[$i] = $scaled % $den; // fractional-part numerator
        }

        $need = $totalTarget - array_sum($floors);

        // Distribute the +1s to the largest remainders first; ties broken by
        // index for determinism. Never exceed the bracket's original value.
        $order = array_keys($remainders);
        usort($order, function (int $a, int $b) use ($remainders): int {
            return $remainders[$b] <=> $remainders[$a] ?: $a <=> $b;
        });

        $target = $floors;

        foreach ($order as $i) {
            if ($need <= 0) {
                break;
            }

            if ($target[$i] >= $orig[$i]) {
                continue;
            }

            $target[$i]++;
            $need--;
        }

        return $target;
    }
}
