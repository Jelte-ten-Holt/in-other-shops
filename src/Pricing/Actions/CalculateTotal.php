<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Pricing\DTOs\PriceBreakdown;
use InOtherShops\Pricing\DTOs\PriceBreakdownLine;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;
use InOtherShops\Pricing\Enums\TaxMode;
use InOtherShops\Pricing\Models\PriceList;
use InOtherShops\Pricing\PricingConfig;
use InOtherShops\Pricing\Support\LargestRemainderAllocator;
use RuntimeException;

/**
 * Composes ResolvePrice + CalculateVoucherDiscount + per-bracket tax into a
 * PriceBreakdown. Under the inclusive tax mode (EU B2C), stored prices are gross:
 * tax is the portion *contained* in the gross, derived per rate bracket, never
 * added on top. Each item carries its own resolved `taxRateBps` (the caller
 * resolves rates — Pricing never reaches into Tax). VAT is summarised by bracket,
 * not per line, matching how invoices and returns present it.
 */
final class CalculateTotal
{
    public function __construct(
        private readonly ResolvePrice $resolvePrice,
        private readonly CalculateVoucherDiscount $calculateVoucherDiscount,
        private readonly CalculateIncludedTax $calculateIncludedTax,
        private readonly LargestRemainderAllocator $allocate,
    ) {}

    /**
     * @param  array<int, array{item: HasPrices, quantity: int, description: string, taxRateBps?: int}>  $items
     */
    public function __invoke(
        array $items,
        Currency $currency,
        int $shippingCost = 0,
        ?PriceList $priceList = null,
        ?string $voucherCode = null,
        ?TaxMode $taxMode = null,
    ): PriceBreakdown {
        $taxMode ??= PricingConfig::defaultTaxMode();

        [$lines, $subtotal] = $this->buildLineItems($items, $currency, $priceList);
        $discount = $this->applyDiscount($voucherCode, $subtotal, $currency);
        $taxBreakdown = $this->computeTaxBreakdown($lines, $subtotal, $discount, $shippingCost, $taxMode);
        $tax = array_sum(array_map(fn (TaxBreakdownLine $b) => $b->tax, $taxBreakdown));

        return new PriceBreakdown(
            subtotal: $subtotal,
            discount: $discount,
            tax: $tax,
            shippingCost: $shippingCost,
            total: $subtotal - $discount + $shippingCost,
            currency: $currency,
            lines: $lines,
            taxBreakdown: $taxBreakdown,
            taxMode: $taxMode,
            voucherCode: $voucherCode,
        );
    }

    /**
     * @param  array<int, array{item: HasPrices, quantity: int, description: string, taxRateBps?: int}>  $items
     * @return array{list<PriceBreakdownLine>, int}
     */
    private function buildLineItems(array $items, Currency $currency, ?PriceList $priceList): array
    {
        $lines = [];
        $subtotal = 0;

        foreach ($items as $item) {
            $price = ($this->resolvePrice)(
                priceable: $item['item'],
                currency: $currency,
                quantity: $item['quantity'],
                priceList: $priceList,
            );

            $unitPrice = $price?->amount ?? 0;
            $lineTotal = $unitPrice * $item['quantity'];
            $subtotal += $lineTotal;

            $lines[] = new PriceBreakdownLine(
                description: $item['description'],
                unitPrice: $unitPrice,
                quantity: $item['quantity'],
                lineTotal: $lineTotal,
                taxRateBps: (int) ($item['taxRateBps'] ?? 0),
            );
        }

        return [$lines, $subtotal];
    }

    private function applyDiscount(?string $voucherCode, int $subtotal, Currency $currency): int
    {
        if ($voucherCode === null) {
            return 0;
        }

        return ($this->calculateVoucherDiscount)($subtotal, $voucherCode, $currency);
    }

    /**
     * Tax grouped by rate bracket (VAT is reported per rate, not per line): sum
     * gross per rate, split the discount across brackets, fold in shipping VAT,
     * summarise each.
     *
     * Shipping (G4): postage is a taxable supply, and under the EU "ancillary
     * supply" rule it follows the rate of the goods it carries. So shipping —
     * already gross-inclusive like every stored price — is apportioned across the
     * goods' brackets in proportion to their gross (the rate mix), and the VAT it
     * contains is extracted alongside the goods'. This changes no customer-facing
     * figure (`total` still = subtotal − discount + shipping); it only stops the
     * VAT inside shipping from being silently dropped. A pure-export (all-0%) cart
     * therefore carries 0% on its shipping too, which add-at-standard-rate would
     * get wrong.
     *
     * @param  list<PriceBreakdownLine>  $lines
     * @return list<TaxBreakdownLine>
     */
    private function computeTaxBreakdown(array $lines, int $subtotal, int $discount, int $shippingCost, TaxMode $taxMode): array
    {
        if ($taxMode === TaxMode::Exclusive) {
            throw new RuntimeException('Exclusive (tax-exclusive) pricing is not implemented yet — B2B seam.');
        }

        // No goods means no rate bracket to attach shipping VAT to — Pricing
        // resolves no rates of its own (rates arrive per line). A shipping-only
        // order can't arise from checkout (an empty cart never reaches it); guard
        // defensively rather than invent a rate.
        if ($subtotal === 0) {
            return [];
        }

        $grossByBracket = $this->sumGrossByBracket($lines);
        $discountByBracket = $this->allocateDiscountAcrossBrackets($grossByBracket, $subtotal, $discount);
        $shippingByBracket = $this->allocateShippingAcrossBrackets($grossByBracket, $subtotal, $shippingCost);

        $breakdown = [];

        foreach ($grossByBracket as $rate => $gross) {
            $breakdown[] = $this->buildTaxBracket($rate, $gross, $discountByBracket[$rate], $shippingByBracket[$rate]);
        }

        return $breakdown;
    }

    /**
     * Sum gross per VAT rate, ascending by rate. Lines that share a rate land in
     * the same bracket.
     *
     * @param  list<PriceBreakdownLine>  $lines
     * @return array<int, int>  rateBps => gross
     */
    private function sumGrossByBracket(array $lines): array
    {
        $grossByBracket = [];

        foreach ($lines as $line) {
            $grossByBracket[$line->taxRateBps] = ($grossByBracket[$line->taxRateBps] ?? 0) + $line->lineTotal;
        }

        ksort($grossByBracket);

        return $grossByBracket;
    }

    /**
     * Split a cart-level discount across brackets, proportional to each bracket's
     * gross share (G5). Largest-remainder, so the rounding cents land on the
     * brackets with the largest fractional shares rather than all on the last
     * (highest-rate) one — which biased that bracket's VAT downward.
     *
     * @param  array<int, int>  $grossByBracket  rateBps => gross
     * @return array<int, int>  rateBps => discount
     */
    private function allocateDiscountAcrossBrackets(array $grossByBracket, int $subtotal, int $discount): array
    {
        $allocated = ($this->allocate)(array_values($grossByBracket), $discount, $subtotal);

        return array_combine(array_keys($grossByBracket), $allocated);
    }

    /**
     * Apportion the gross shipping charge across the goods' brackets in proportion
     * to their gross (G4 — the ancillary-supply rate mix). Largest-remainder, and
     * NOT capped at each bracket's gross: a small cart with expensive postage
     * legitimately apportions more shipping to a bracket than that bracket's own
     * gross, and capping would drop the excess shipping VAT.
     *
     * @param  array<int, int>  $grossByBracket  rateBps => gross
     * @return array<int, int>  rateBps => shipping share
     */
    private function allocateShippingAcrossBrackets(array $grossByBracket, int $subtotal, int $shippingCost): array
    {
        if ($shippingCost === 0) {
            return array_map(static fn (): int => 0, $grossByBracket);
        }

        $allocated = ($this->allocate)(array_values($grossByBracket), $shippingCost, $subtotal, capAtWeight: false);

        return array_combine(array_keys($grossByBracket), $allocated);
    }

    /**
     * One bracket's tax summary: remove its share of the discount, add its share
     * of shipping (both at this bracket's rate), then split the resulting gross
     * into net + the VAT it contains. All rounding lives in
     * {@see CalculateIncludedTax}, so net and tax can't round inconsistently.
     */
    private function buildTaxBracket(int $rate, int $gross, int $discount, int $shipping): TaxBreakdownLine
    {
        $taxableGross = $gross - $discount + $shipping;              // discounted goods + shipping share, still tax-inclusive
        $tax = ($this->calculateIncludedTax)($taxableGross, $rate);  // the VAT contained in it
        $net = $taxableGross - $tax;                                 // net = gross minus that VAT

        return new TaxBreakdownLine(rateBps: $rate, taxableBase: $net, tax: $tax);
    }
}
