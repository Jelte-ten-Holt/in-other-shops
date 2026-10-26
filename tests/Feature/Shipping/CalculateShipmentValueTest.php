<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;
use InOtherShops\Shipping\Actions\CalculateShipmentValue;
use InOtherShops\Shipping\DTOs\ShipmentValue;
use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Shipping\Models\ShipmentItem;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a shipment carries of its order's value. The case it exists for: an
 * order mixing a 7 % pre-order book and a 19 % in-stock poster ships twice,
 * and each shipment must carry its own rate's share, shipping and discount
 * included, the two adding up to the order to the cent.
 */
final class CalculateShipmentValueTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private OrderLine $book;

    private OrderLine $poster;

    protected function setUp(): void
    {
        parent::setUp();

        // The brackets carry shipping and discount the way CalculateTotal
        // apportions them; the allocator only follows the brackets.
        $this->order = Order::factory()->create([
            'total' => 7963,
            'tax_rate_bps' => 700,
            'tax_summary' => [
                ['rate_bps' => 700, 'taxable_base' => 5233, 'tax' => 366],
                ['rate_bps' => 1900, 'taxable_base' => 1987, 'tax' => 377],
            ],
        ]);
        $this->book = OrderLine::factory()->create(['order_id' => $this->order->id, 'line_total' => 5500, 'quantity' => 1, 'tax_rate_bps' => 700]);
        $this->poster = OrderLine::factory()->create(['order_id' => $this->order->id, 'line_total' => 2400, 'quantity' => 2, 'tax_rate_bps' => 1900]);
    }

    #[Test]
    public function a_whole_order_shipment_carries_the_order_unchanged(): void
    {
        $value = $this->value($this->ship([$this->book->id => 1, $this->poster->id => 2]));

        $this->assertSame(7963, $value->amount);
        $this->assertSame($this->order->tax_summary, TaxBreakdownLine::serializeMany($value->taxSummary));
    }

    #[Test]
    public function two_shipments_each_carry_their_own_rate_and_add_up_to_the_order(): void
    {
        $posters = $this->ship([$this->poster->id => 2]);
        $book = $this->ship([$this->book->id => 1]);

        $first = $this->value($posters);
        $second = $this->value($book);

        $this->assertSame([['rate_bps' => 1900, 'taxable_base' => 1987, 'tax' => 377]], TaxBreakdownLine::serializeMany($first->taxSummary));
        $this->assertSame([['rate_bps' => 700, 'taxable_base' => 5233, 'tax' => 366]], TaxBreakdownLine::serializeMany($second->taxSummary));
        $this->assertSame(7963, $first->amount + $second->amount);
    }

    #[Test]
    public function a_line_shipped_in_parts_splits_by_quantity_and_the_last_part_takes_the_remainder(): void
    {
        $one = $this->ship([$this->poster->id => 1]);
        $rest = $this->ship([$this->poster->id => 1, $this->book->id => 1]);

        $first = $this->value($one);
        $second = $this->value($rest);

        $this->assertSame([['rate_bps' => 1900, 'taxable_base' => 994, 'tax' => 189]], TaxBreakdownLine::serializeMany($first->taxSummary));
        $this->assertSame(7963, $first->amount + $second->amount);
        $this->assertSame(1987, $first->taxSummary[0]->taxableBase + $this->bracket($second, 1900)->taxableBase);
        $this->assertSame(377, $first->taxSummary[0]->tax + $this->bracket($second, 1900)->tax);
    }

    #[Test]
    public function a_shipments_value_does_not_move_when_a_later_shipment_is_added(): void
    {
        $one = $this->ship([$this->poster->id => 1]);
        $before = $this->value($one);

        $this->ship([$this->poster->id => 1, $this->book->id => 1]);

        $this->assertEquals($before, $this->value($one->fresh()));
    }

    #[Test]
    public function a_reshipment_after_a_return_carries_nothing_more(): void
    {
        $this->ship([$this->book->id => 1, $this->poster->id => 2]);
        $again = $this->ship([$this->poster->id => 2]);

        $value = $this->value($again);

        $this->assertSame(0, $value->amount);
        $this->assertSame([], $value->taxSummary);
    }

    #[Test]
    public function a_reshipped_line_does_not_stand_in_for_a_line_that_never_shipped(): void
    {
        $mug = OrderLine::factory()->create(['order_id' => $this->order->id, 'line_total' => 1200, 'quantity' => 1, 'tax_rate_bps' => 1900]);
        $this->ship([$this->poster->id => 2, $this->book->id => 1]);
        $again = $this->ship([$this->poster->id => 2]);
        $mugs = $this->ship([$mug->id => 1]);

        $this->assertSame(0, $this->value($again)->amount);
        // The mug's third of the 19 % goods is still owed to its own shipment.
        $this->assertSame(['rate_bps' => 1900, 'taxable_base' => 662, 'tax' => 126], TaxBreakdownLine::serializeMany($this->value($mugs)->taxSummary)[0]);
    }

    #[Test]
    public function a_bracket_no_line_falls_in_follows_the_order_as_a_whole(): void
    {
        // Every line at 7 %, yet a 19 % bracket: it goes with half the goods.
        $this->poster->update(['tax_rate_bps' => 700]);

        $value = $this->value($this->ship([$this->poster->id => 2]));

        $this->assertSame(['rate_bps' => 1900, 'taxable_base' => 604, 'tax' => 115], TaxBreakdownLine::serializeMany([$this->bracket($value, 1900)])[0]);
    }

    /**
     * in-other-worlds creates a whole-order shipment when the order is placed.
     * A pre-order cart then sends the in-stock posters first in a shipment of
     * their own, and the up-front one goes out later with the book: each is
     * valued by what had gone out before it, not by when it was created.
     */
    #[Test]
    public function shipments_are_valued_in_the_order_they_went_out_not_the_order_they_were_created(): void
    {
        $upFront = $this->ship([$this->book->id => 1, $this->poster->id => 2]);
        $posters = $this->ship([$this->poster->id => 2]);
        $posters->update(['shipped_at' => '2026-12-03 10:00:00']);
        $upFront->update(['shipped_at' => '2027-01-10 10:00:00']);

        $first = $this->value($posters);
        $second = $this->value($upFront);

        $this->assertSame([['rate_bps' => 1900, 'taxable_base' => 1987, 'tax' => 377]], TaxBreakdownLine::serializeMany($first->taxSummary));
        $this->assertSame([['rate_bps' => 700, 'taxable_base' => 5233, 'tax' => 366]], TaxBreakdownLine::serializeMany($second->taxSummary));
    }

    #[Test]
    public function a_shipment_not_yet_dispatched_comes_after_every_dispatched_one(): void
    {
        $waiting = $this->ship([$this->book->id => 1, $this->poster->id => 2]);
        $posters = $this->ship([$this->poster->id => 2]);
        $posters->update(['shipped_at' => '2026-12-03 10:00:00']);

        $this->assertSame(1987 + 377, $this->value($posters)->amount);
        $this->assertSame(5233 + 366, $this->value($waiting)->amount);
    }

    #[Test]
    public function an_order_without_a_tax_summary_is_one_bracket_at_its_rate(): void
    {
        $this->order->update(['tax_summary' => null, 'tax' => 521]);

        $value = $this->value($this->ship([$this->book->id => 1, $this->poster->id => 2]));

        $this->assertSame(7963, $value->amount);
        $this->assertSame([['rate_bps' => 700, 'taxable_base' => 7442, 'tax' => 521]], TaxBreakdownLine::serializeMany($value->taxSummary));
    }

    /** @param  array<int, int>  $quantities  order line id => quantity */
    private function ship(array $quantities): Shipment
    {
        $shipment = Shipment::factory()->for($this->order, 'shippable')->create();

        foreach ($quantities as $lineId => $quantity) {
            ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'order_line_id' => $lineId, 'quantity' => $quantity]);
        }

        return $shipment;
    }

    private function value(Shipment $shipment): ShipmentValue
    {
        return app(CalculateShipmentValue::class)(Shipment::query()->findOrFail($shipment->id));
    }

    private function bracket(ShipmentValue $value, int $rateBps): TaxBreakdownLine
    {
        foreach ($value->taxSummary as $line) {
            if ($line->rateBps === $rateBps) {
                return $line;
            }
        }

        $this->fail("No {$rateBps} bracket.");
    }
}
