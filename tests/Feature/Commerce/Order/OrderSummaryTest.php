<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Commerce\Order\Support\OrderSummary;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The one shopper-facing projection of a persisted order. Reads columns,
 * never recomputes; cents + pre-formatted strings; PII stays out.
 */
final class OrderSummaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_projects_the_persisted_totals_with_formatted_companions(): void
    {
        $order = $this->order([
            'subtotal' => 5000,
            'tax' => 798,
            'discount' => 1000,
            'total' => 4595,
            'shipping_cost' => 595,
            'shipping_method_identifier' => 'standard',
            'voucher_code' => 'TENOFF',
        ]);

        $summary = OrderSummary::for($order);

        $this->assertSame(5000, $summary['subtotal']);
        $this->assertSame(Currency::EUR->format(5000), $summary['formattedSubtotal']);
        $this->assertSame(798, $summary['tax']);
        $this->assertSame(4595, $summary['total']);
        $this->assertTrue($summary['hasDiscount']);
        $this->assertSame(1000, $summary['discount']);
        $this->assertSame('TENOFF', $summary['voucherCode']);
        $this->assertTrue($summary['requiresShipping']);
        $this->assertSame(595, $summary['shippingCost']);
        $this->assertSame($order->order_number, $summary['orderNumber']);
        $this->assertSame(OrderStatus::Pending->value, $summary['status']);
    }

    #[Test]
    public function an_undiscounted_order_shows_no_discount(): void
    {
        $summary = OrderSummary::for($this->order(['discount' => 0, 'voucher_code' => null]));

        $this->assertFalse($summary['hasDiscount']);
        $this->assertNull($summary['voucherCode']);
    }

    #[Test]
    public function an_order_that_never_shipped_hides_the_shipping_line_entirely(): void
    {
        // Derived from the method snapshot, not the cost: "Shipping €0.00" on
        // a digital order reads as "postage was free", not "no postage".
        $summary = OrderSummary::for($this->order([
            'shipping_method_identifier' => null,
            'shipping_cost' => 0,
        ]));

        $this->assertFalse($summary['requiresShipping']);
    }

    #[Test]
    public function the_vat_line_follows_the_shows_vat_config(): void
    {
        $order = $this->order([]);

        $this->assertTrue(OrderSummary::for($order)['showsVat']);

        // A shop that charges no VAT (e.g. §19 Kleinunternehmer) must not
        // show the line at all — on an invoice it would be actively wrong.
        config()->set('commerce.order.summary.shows_vat', false);

        $this->assertFalse(OrderSummary::for($order)['showsVat']);
    }

    #[Test]
    public function lines_carry_the_package_columns_including_pre_order_facts(): void
    {
        $order = $this->order([]);
        OrderLine::factory()->for($order)->create([
            'description' => 'Pendant',
            'unit_price' => 2500,
            'quantity' => 2,
            'line_total' => 5000,
            'is_pre_order' => true,
            'expected_ship_date' => '2026-10-01',
        ]);

        $summary = OrderSummary::for($order->fresh(), withLines: true);

        $this->assertCount(1, $summary['lines']);
        $line = $summary['lines'][0];
        $this->assertSame('Pendant', $line['description']);
        $this->assertSame(2, $line['quantity']);
        $this->assertSame(2500, $line['unitPrice']);
        $this->assertSame(Currency::EUR->format(2500), $line['formattedUnitPrice']);
        $this->assertSame(5000, $line['lineTotal']);
        $this->assertTrue($line['isPreOrder']);
        $this->assertSame('2026-10-01', $line['expectedShipDate']);
    }

    #[Test]
    public function without_lines_the_key_is_absent_not_empty(): void
    {
        $this->assertArrayNotHasKey('lines', OrderSummary::for($this->order([])));
    }

    #[Test]
    public function it_exposes_no_address_and_no_email(): void
    {
        // An order view reachable by a signed link is held by anyone with the
        // URL; PII is added per page by the consumer, never by the projection.
        $summary = OrderSummary::for($this->order(['email' => 'shopper@example.com']), withLines: true);

        $flattened = json_encode($summary);

        $this->assertStringNotContainsString('shopper@example.com', (string) $flattened);
        $this->assertArrayNotHasKey('email', $summary);
        $this->assertArrayNotHasKey('billingAddress', $summary);
        $this->assertArrayNotHasKey('shippingAddress', $summary);
    }

    /** @param array<string, mixed> $attributes */
    private function order(array $attributes): Order
    {
        return Order::factory()->create(array_merge([
            'currency' => Currency::EUR,
            'status' => OrderStatus::Pending,
            'subtotal' => 5000,
            'tax' => 798,
            'discount' => 0,
            'total' => 5000,
        ], $attributes));
    }
}
