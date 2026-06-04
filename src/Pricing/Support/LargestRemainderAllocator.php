<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Support;

/**
 * Largest-remainder (Hamilton) apportionment of an integer quantity across
 * buckets, weighted by each bucket's value. Distributes
 * `round(sum(weights) × num / den)` so the parts sum exactly to that target,
 * handing each rounding cent to the largest fractional remainder first; ties
 * break by bucket index for determinism. Pure integer math — no float drift.
 *
 * Three pricing callers share it:
 *  - {@see \InOtherShops\Commerce\Order\Actions\ReverseTax} — reverse cumulative
 *    VAT per bracket on a refund (num = cumulative refunded, den = order total);
 *  - per-bracket voucher discount allocation (num = discount, den = subtotal);
 *  - shipping-VAT apportionment across goods brackets (num = shipping, den = subtotal).
 *
 * `capAtWeight` bounds each bucket at its own weight — correct where over-
 * allocation is a domain error (you can never reverse more VAT than was charged,
 * nor discount a bracket below zero). The shipping case passes `false`: a small
 * cart with expensive postage legitimately apportions more shipping than a
 * bracket's own gross, so capping there would silently under-collect.
 */
final class LargestRemainderAllocator
{
    /**
     * @param  list<int>  $weights  per-bucket weight (gross, tax, or taxable base)
     * @param  int  $num  numerator of the proportion to distribute
     * @param  int  $den  denominator of the proportion
     * @param  bool  $capAtWeight  cap each bucket at its own weight (default true)
     * @return list<int>  per-bucket allocation, same order and keys as $weights
     */
    public function __invoke(array $weights, int $num, int $den, bool $capAtWeight = true): array
    {
        $total = array_sum($weights);

        if ($total <= 0 || $den <= 0) {
            return array_fill(0, count($weights), 0);
        }

        // round-half-up of total × num / den, integer-only
        $totalTarget = intdiv(2 * $total * $num + $den, 2 * $den);

        $floors = [];
        $remainders = [];

        foreach ($weights as $i => $value) {
            $scaled = $value * $num;
            $floors[$i] = intdiv($scaled, $den);
            $remainders[$i] = $scaled % $den; // fractional-part numerator
        }

        $need = $totalTarget - array_sum($floors);

        // Distribute the +1s to the largest remainders first; ties broken by
        // index for determinism.
        $order = array_keys($remainders);
        usort($order, fn (int $a, int $b): int => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);

        $target = $floors;

        foreach ($order as $i) {
            if ($need <= 0) {
                break;
            }

            if ($capAtWeight && $target[$i] >= $weights[$i]) {
                continue;
            }

            $target[$i]++;
            $need--;
        }

        return $target;
    }
}
