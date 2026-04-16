<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Pricing\DTOs\PriceBreakdown;
use InOtherShops\Pricing\DTOs\PriceBreakdownLine;
use InOtherShops\Pricing\Models\PriceList;

final class CalculateTotal
{
    public function __construct(
        private readonly ResolvePrice $resolvePrice,
        private readonly CalculateVoucherDiscount $calculateVoucherDiscount,
        private readonly CalculateTax $calculateTax,
    ) {}

    /**
     * @param  array<int, array{item: HasPrices, quantity: int, description: string}>  $items
     */
    public function __invoke(
        array $items,
        Currency $currency,
        int $taxRate,
        ?PriceList $priceList = null,
        ?string $voucherCode = null,
    ): PriceBreakdown {
        [$lines, $subtotal] = $this->buildLineItems($items, $currency, $priceList);
        $discount = $this->applyDiscount($voucherCode, $subtotal, $currency);
        $tax = $this->computeTax($subtotal, $discount, $taxRate);

        return $this->buildBreakdown($subtotal, $discount, $tax, $currency, $lines, $voucherCode);
    }

    /**
     * @param  array<int, array{item: HasPrices, quantity: int, description: string}>  $items
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

    private function computeTax(int $subtotal, int $discount, int $taxRate): int
    {
        return ($this->calculateTax)($subtotal - $discount, $taxRate);
    }

    /**
     * @param  list<PriceBreakdownLine>  $lines
     */
    private function buildBreakdown(
        int $subtotal,
        int $discount,
        int $tax,
        Currency $currency,
        array $lines,
        ?string $voucherCode,
    ): PriceBreakdown {
        return new PriceBreakdown(
            subtotal: $subtotal,
            discount: $discount,
            tax: $tax,
            total: $subtotal - $discount + $tax,
            currency: $currency,
            lines: $lines,
            voucherCode: $voucherCode,
        );
    }
}
