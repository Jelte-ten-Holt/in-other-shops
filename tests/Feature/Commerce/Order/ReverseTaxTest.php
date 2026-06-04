<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use InOtherShops\Commerce\Order\Actions\ReverseTax;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;
use InOtherShops\Pricing\Support\LargestRemainderAllocator;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The refund tax-reversal allocator. The critical property: a sequence of
 * partial refunds that sums to the full order must reverse EXACTLY the tax that
 * was charged, per bracket — no rounding drift. The naive "proportional on each
 * refund amount" design under-reversed (210c charged, 207c reversed); the
 * cumulative-anchored allocator reconciles to the cent.
 */
final class ReverseTaxTest extends TestCase
{
    private ReverseTax $reverse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reverse = new ReverseTax(new LargestRemainderAllocator);
    }

    #[Test]
    public function a_full_refund_reverses_each_bracket_to_exactly_its_charged_tax(): void
    {
        $brackets = [
            new TaxBreakdownLine(rateBps: 1900, taxableBase: 843, tax: 160),
            new TaxBreakdownLine(rateBps: 700, taxableBase: 707, tax: 50),
        ];

        $deltas = ($this->reverse)($brackets, originalAmount: 1760, cumulativeRefunded: 1760);

        $this->assertSame([1900 => 160, 700 => 50], $this->taxByRate($deltas));
        $this->assertSame([1900 => 843, 700 => 707], $this->baseByRate($deltas));
    }

    #[Test]
    public function a_sequence_of_partial_refunds_summing_to_full_reconciles_exactly_per_bracket(): void
    {
        // The drift case from the critique: independent per-refund rounding would
        // leave 3c of VAT unreversed (207 of 210). Cumulative anchoring fixes it.
        $brackets = [
            new TaxBreakdownLine(rateBps: 1900, taxableBase: 843, tax: 160),
            new TaxBreakdownLine(rateBps: 700, taxableBase: 707, tax: 50),
        ];

        [$tax, $base] = $this->applySequence($brackets, 1760, [587, 587, 586]);

        $this->assertSame(160, $tax[1900], '19% bracket must reverse its full charged tax');
        $this->assertSame(50, $tax[700], '7% bracket must reverse its full charged tax');
        $this->assertSame(210, array_sum($tax), 'total reversed VAT must equal total charged VAT');

        // The taxable-base column reconciles to the cent too.
        $this->assertSame(843, $base[1900]);
        $this->assertSame(707, $base[700]);
    }

    #[Test]
    public function an_odd_uneven_split_still_reconciles_and_never_over_reverses_a_bracket(): void
    {
        $brackets = [
            new TaxBreakdownLine(rateBps: 1900, taxableBase: 1681, tax: 319),
            new TaxBreakdownLine(rateBps: 700, taxableBase: 935, tax: 65),
        ];
        $total = 3000;

        // Deliberately awkward partials.
        [$tax] = $this->applySequence($brackets, $total, [777, 1, 999, 1223]);

        $this->assertSame(319, $tax[1900]);
        $this->assertSame(65, $tax[700]);
        $this->assertSame(384, array_sum($tax));
    }

    #[Test]
    public function a_partial_refund_never_reverses_more_tax_than_charged_in_any_bracket(): void
    {
        $brackets = [
            new TaxBreakdownLine(rateBps: 1900, taxableBase: 843, tax: 160),
            new TaxBreakdownLine(rateBps: 700, taxableBase: 707, tax: 50),
        ];

        // Many tiny refunds; assert the running cumulative never exceeds the charged tax.
        $alreadyTax = [];
        $alreadyBase = [];
        $cum = 0;

        foreach (array_fill(0, 176, 10) as $step) { // 176 × 10 = 1760 (full)
            $cum += $step;
            foreach (($this->reverse)($brackets, 1760, $cum, $alreadyTax, $alreadyBase) as $d) {
                $alreadyTax[$d->rateBps] = ($alreadyTax[$d->rateBps] ?? 0) + $d->tax;
                $alreadyBase[$d->rateBps] = ($alreadyBase[$d->rateBps] ?? 0) + $d->taxableBase;
                $this->assertGreaterThanOrEqual(0, $d->tax, 'no negative reversal delta');
            }
            $this->assertLessThanOrEqual(160, $alreadyTax[1900] ?? 0);
            $this->assertLessThanOrEqual(50, $alreadyTax[700] ?? 0);
        }

        $this->assertSame(160, $alreadyTax[1900]);
        $this->assertSame(50, $alreadyTax[700]);
    }

    #[Test]
    public function a_zero_rated_bracket_never_borrows_a_cent_of_reversed_tax(): void
    {
        $brackets = [
            new TaxBreakdownLine(rateBps: 1900, taxableBase: 1000, tax: 190),
            new TaxBreakdownLine(rateBps: 0, taxableBase: 1000, tax: 0), // zero-rated / export
        ];

        // Half refund — the largest-remainder step must not push a cent into the 0% bracket.
        $deltas = ($this->reverse)($brackets, originalAmount: 2190, cumulativeRefunded: 1095);

        $taxByRate = $this->taxByRate($deltas);
        $this->assertSame(0, $taxByRate[0] ?? 0, 'zero-rated bracket must reverse zero tax');
        $this->assertArrayHasKey(1900, $taxByRate);
    }

    #[Test]
    public function it_returns_nothing_for_a_zero_amount_or_empty_breakdown(): void
    {
        $this->assertSame([], ($this->reverse)([], originalAmount: 1000, cumulativeRefunded: 500));
        $this->assertSame([], ($this->reverse)(
            [new TaxBreakdownLine(1900, 843, 160)],
            originalAmount: 0,
            cumulativeRefunded: 500,
        ));
    }

    /**
     * @param  list<TaxBreakdownLine>  $brackets
     * @param  list<int>  $refunds
     * @return array{0: array<int, int>, 1: array<int, int>}  [taxByRate, baseByRate] cumulative
     */
    private function applySequence(array $brackets, int $total, array $refunds): array
    {
        $tax = [];
        $base = [];
        $cum = 0;

        foreach ($refunds as $refund) {
            $cum += $refund;
            foreach (($this->reverse)($brackets, $total, $cum, $tax, $base) as $d) {
                $tax[$d->rateBps] = ($tax[$d->rateBps] ?? 0) + $d->tax;
                $base[$d->rateBps] = ($base[$d->rateBps] ?? 0) + $d->taxableBase;
            }
        }

        return [$tax, $base];
    }

    /**
     * @param  list<TaxBreakdownLine>  $deltas
     * @return array<int, int>
     */
    private function taxByRate(array $deltas): array
    {
        $out = [];
        foreach ($deltas as $d) {
            $out[$d->rateBps] = $d->tax;
        }

        return $out;
    }

    /**
     * @param  list<TaxBreakdownLine>  $deltas
     * @return array<int, int>
     */
    private function baseByRate(array $deltas): array
    {
        $out = [];
        foreach ($deltas as $d) {
            $out[$d->rateBps] = $d->taxableBase;
        }

        return $out;
    }
}
