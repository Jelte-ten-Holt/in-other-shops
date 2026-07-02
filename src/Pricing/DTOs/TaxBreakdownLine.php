<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\DTOs;

/**
 * One rate bracket of a tax breakdown — the shape an invoice / VAT return needs:
 * a taxable base (net), the rate, and the tax on it. VAT is reported per rate,
 * not per line item, so tax is summarised by bracket. All amounts integer cents.
 */
final readonly class TaxBreakdownLine
{
    public function __construct(
        public int $rateBps,
        public int $taxableBase,
        public int $tax,
    ) {}

    /**
     * Rebuild a per-rate breakdown from its persisted `tax_summary` rows.
     *
     * The single reader for both Order and Refund — keeping decode in one place
     * is what stops the two accessors drifting apart. Values are coerced to
     * integer cents (a JSON round-trip can surface numeric strings).
     *
     * @param  list<array{rate_bps: mixed, taxable_base: mixed, tax: mixed}>|null  $rows
     * @return list<self>
     */
    public static function listFromRows(?array $rows): array
    {
        return array_map(
            static fn (array $row): self => new self(
                rateBps: (int) $row['rate_bps'],
                taxableBase: (int) $row['taxable_base'],
                tax: (int) $row['tax'],
            ),
            $rows ?? [],
        );
    }

    /**
     * Serialize a per-rate breakdown to its persisted `tax_summary` rows — the
     * exact inverse of {@see self::listFromRows()}, and the single writer, so
     * read and write cannot encode different shapes.
     *
     * @param  list<self>  $lines
     * @return list<array{rate_bps: int, taxable_base: int, tax: int}>
     */
    public static function serializeMany(array $lines): array
    {
        return array_map(
            static fn (self $line): array => [
                'rate_bps' => $line->rateBps,
                'taxable_base' => $line->taxableBase,
                'tax' => $line->tax,
            ],
            $lines,
        );
    }
}
