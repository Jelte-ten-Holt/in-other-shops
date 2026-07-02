<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * T-B1 — one persisted VAT breakdown shape, one reader, one writer.
 *
 * The `tax_summary` row shape (`{rate_bps, taxable_base, tax}`) was encoded at
 * four sites — Order/Refund read accessors and CreateOrder/RecordRefund write
 * paths. Any one drifting silently corrupts VAT-return accounting. These tests
 * pin the read↔write inverse on the shared DTO and prove Order and Refund
 * decode identically, so a future edit to the shape must move all sites or
 * fail here.
 */
final class TaxSummaryShapeTest extends TestCase
{
    /** @var list<array{rate_bps: int, taxable_base: int, tax: int}> */
    private const array ROWS = [
        ['rate_bps' => 2100, 'taxable_base' => 8000, 'tax' => 1680],
        ['rate_bps' => 900, 'taxable_base' => 5000, 'tax' => 450],
    ];

    #[Test]
    public function serialize_is_the_inverse_of_read_on_the_dto(): void
    {
        $lines = TaxBreakdownLine::listFromRows(self::ROWS);

        // write(read(rows)) == rows
        $this->assertSame(self::ROWS, TaxBreakdownLine::serializeMany($lines));
        // read(write(lines)) == lines
        $this->assertEquals($lines, TaxBreakdownLine::listFromRows(TaxBreakdownLine::serializeMany($lines)));

        // Not a trivially-reflexive pass: assert the decoded field values.
        $this->assertSame(2100, $lines[0]->rateBps);
        $this->assertSame(8000, $lines[0]->taxableBase);
        $this->assertSame(1680, $lines[0]->tax);
    }

    #[Test]
    public function an_absent_breakdown_reads_as_an_empty_list(): void
    {
        $this->assertSame([], TaxBreakdownLine::listFromRows(null));
        $this->assertSame([], TaxBreakdownLine::listFromRows([]));
    }

    #[Test]
    public function the_reader_coerces_persisted_values_to_int(): void
    {
        // JSON round-trips can hand back numeric strings; the breakdown must be
        // integer cents regardless (accounting maths depends on it).
        $lines = TaxBreakdownLine::listFromRows([
            ['rate_bps' => '2100', 'taxable_base' => '8000', 'tax' => '1680'],
        ]);

        $this->assertSame(2100, $lines[0]->rateBps);
        $this->assertSame(8000, $lines[0]->taxableBase);
        $this->assertSame(1680, $lines[0]->tax);
    }

    #[Test]
    public function order_and_refund_decode_the_same_rows_to_the_same_breakdown(): void
    {
        $order = new Order;
        $order->tax_summary = self::ROWS;

        $refund = new Refund;
        $refund->tax_summary = self::ROWS;

        // Both models must produce identical DTO shapes for identical rows —
        // the whole point of collapsing the two accessors onto one DTO reader.
        $this->assertEquals($order->taxSummary(), $refund->taxSummary());
        $this->assertEquals(TaxBreakdownLine::listFromRows(self::ROWS), $order->taxSummary());
    }
}
