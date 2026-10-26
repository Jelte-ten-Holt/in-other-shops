<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;
use InOtherShops\Shipping\DTOs\ShipmentValue;
use InOtherShops\Shipping\Models\Shipment;

/**
 * What one shipment of an order carries of the order's value, for the
 * bookkeeping read API (P-5): for a pre-order shop the shipment, not the
 * payment, is the delivery that turns an advance into revenue, and an order
 * mixing a pre-order and an in-stock line ships twice.
 *
 * By the lines it holds. Each rate bracket of the order's own tax summary —
 * which already carries its share of shipping cost and discount — goes to the
 * shipments in proportion to the bracket's goods they carry (line total ×
 * quantity shipped / quantity ordered). The allocation is cumulative over the
 * order's shipments in id order, each line's shipped quantity capped at what
 * was ordered, so:
 *
 * - a whole-order shipment carries the order's total and summary unchanged;
 * - the shipments of a fully shipped order add up to it exactly;
 * - a shipment's value does not move when a later shipment is added.
 *
 * A bracket no line falls in (an older order whose lines carry no rate) goes
 * with the order's goods as a whole. A re-shipment after a return or a loss
 * carries nothing more: the line was already counted shipped.
 */
final class CalculateShipmentValue
{
    public function __invoke(Shipment $shipment): ShipmentValue
    {
        /** @var Order $order */
        $order = $shipment->shippable;
        $order->loadMissing(['lines', 'shipments.items']);

        $before = [];
        $after = [];

        foreach ($order->shipments->sortBy('id') as $sibling) {
            if ($sibling->getKey() > $shipment->getKey()) {
                continue;
            }

            foreach ($sibling->items as $item) {
                $after[$item->order_line_id] = ($after[$item->order_line_id] ?? 0) + $item->quantity;

                if ($sibling->getKey() < $shipment->getKey()) {
                    $before[$item->order_line_id] = ($before[$item->order_line_id] ?? 0) + $item->quantity;
                }
            }
        }

        $summary = [];
        $amount = 0;

        foreach ($this->brackets($order) as $bracket) {
            $shareBefore = $this->share($order, $bracket->rateBps, $before);
            $shareAfter = $this->share($order, $bracket->rateBps, $after);

            $line = new TaxBreakdownLine(
                rateBps: $bracket->rateBps,
                taxableBase: $this->portion($bracket->taxableBase, $shareAfter) - $this->portion($bracket->taxableBase, $shareBefore),
                tax: $this->portion($bracket->tax, $shareAfter) - $this->portion($bracket->tax, $shareBefore),
            );

            if ($line->taxableBase === 0 && $line->tax === 0) {
                continue; // a bracket this shipment carries none of
            }

            $summary[] = $line;
            $amount += $line->taxableBase + $line->tax;
        }

        return new ShipmentValue(amount: $amount, currency: $order->currency, taxSummary: $summary);
    }

    /**
     * The order's own summary; an order that has none is one bracket at its
     * single rate.
     *
     * @return list<TaxBreakdownLine>
     */
    private function brackets(Order $order): array
    {
        return $order->taxSummary() ?: [
            new TaxBreakdownLine((int) $order->tax_rate_bps, (int) $order->total - (int) $order->tax, (int) $order->tax),
        ];
    }

    /**
     * The fraction of a bracket's goods shipped once `$shipped` (quantity per
     * line id) has gone out; the whole order's fraction for a bracket no line
     * falls in.
     *
     * @param  array<int, int>  $shipped
     */
    private function share(Order $order, int $rateBps, array $shipped): float
    {
        $inBracket = $order->lines->filter(fn (OrderLine $line): bool => $this->rateOf($order, $line) === $rateBps);
        $lines = $inBracket->isEmpty() ? $order->lines : $inBracket;

        $goods = 0;
        $out = 0.0;

        foreach ($lines as $line) {
            $goods += $line->line_total;
            $quantity = min($shipped[$line->getKey()] ?? 0, $line->quantity);
            $out += $line->quantity > 0 ? $line->line_total * $quantity / $line->quantity : 0;
        }

        if ($goods <= 0) {
            return $lines->every(fn (OrderLine $line): bool => ($shipped[$line->getKey()] ?? 0) >= $line->quantity) ? 1.0 : 0.0;
        }

        return min(1.0, $out / $goods);
    }

    private function portion(int $amount, float $share): int
    {
        return $share >= 1.0 ? $amount : (int) round($amount * $share);
    }

    private function rateOf(Order $order, OrderLine $line): int
    {
        return $line->tax_rate_bps ?? (int) $order->tax_rate_bps;
    }
}
