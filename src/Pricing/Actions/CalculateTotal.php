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
        $taxBreakdown = $this->computeTaxBreakdown($lines, $subtotal, $discount, $taxMode);
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
     * Tax grouped by rate bracket (VAT is reported per rate, not per line):
     * sum gross per rate, split the discount across brackets, summarise each.
     *
     * @param  list<PriceBreakdownLine>  $lines
     * @return list<TaxBreakdownLine>
     */
    private function computeTaxBreakdown(array $lines, int $subtotal, int $discount, TaxMode $taxMode): array
    {
        if ($taxMode === TaxMode::Exclusive) {
            throw new RuntimeException('Exclusive (tax-exclusive) pricing is not implemented yet — B2B seam.');
        }

        if ($subtotal === 0) {
            return [];
        }

        $grossByBracket = $this->sumGrossByBracket($lines);
        $discountByBracket = $this->allocateDiscountAcrossBrackets($grossByBracket, $subtotal, $discount);

        $breakdown = [];

        foreach ($grossByBracket as $rate => $gross) {
            $breakdown[] = $this->buildTaxBracket($rate, $gross, $discountByBracket[$rate]);
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
     * gross share; the rounding remainder goes to the last bracket so the parts
     * sum back to the whole.
     *
     * @param  array<int, int>  $grossByBracket  rateBps => gross
     * @return array<int, int>  rateBps => discount
     */
    private function allocateDiscountAcrossBrackets(array $grossByBracket, int $subtotal, int $discount): array
    {
        $rates = array_keys($grossByBracket);
        $lastRate = end($rates);
        $allocated = 0;
        $discountByBracket = [];

        foreach ($grossByBracket as $rate => $gross) {
            $discountByBracket[$rate] = $rate === $lastRate
                ? $discount - $allocated
                : (int) floor($discount * $gross / $subtotal);
            $allocated += $discountByBracket[$rate];
        }

        return $discountByBracket;
    }

    /**
     * One bracket's tax summary: remove its share of the discount, then split the
     * remaining gross into net + the VAT it contains. All rounding lives in
     * {@see CalculateIncludedTax}, so net and tax can't round inconsistently.
     */
    private function buildTaxBracket(int $rate, int $gross, int $discount): TaxBreakdownLine
    {
        $taxableGross = $gross - $discount;                          // after discount, still tax-inclusive
        $tax = ($this->calculateIncludedTax)($taxableGross, $rate);  // the VAT contained in it
        $net = $taxableGross - $tax;                                 // net = gross minus that VAT

        return new TaxBreakdownLine(rateBps: $rate, taxableBase: $net, tax: $tax);
    }
}
