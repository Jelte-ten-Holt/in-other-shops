<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Support;

use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;

/**
 * The shopper-facing projection of a persisted order — the shape the pay
 * page, the confirmation page, the order view, and the confirmation mail all
 * render, shared so the totals a shopper sees never disagree between the
 * page that took their money and the page that confirms it.
 *
 * Reads persisted columns ONLY, never recomputes. Every amount ships both as
 * raw cents and pre-formatted via the order's own Currency::format() —
 * formatted strings live in presenters, cents live in DTOs (the package's
 * dividing line). Formatting follows the AMBIENT locale: a mailable rendering
 * in a queue worker must set `$this->locale(...)` or every amount comes out
 * in the worker's default locale.
 *
 * DATA, NOT DECORATION: the presenter emits package data; anything
 * presentation-specific — a status label/color pair, a per-line product URL —
 * is the consumer's to add by mapping over this array (labels are i18n, and
 * i18n is the app's).
 *
 * Deliberately omits the billing/shipping addresses and the customer email.
 * Pay/confirmation pages are session-gated, but an order view reachable by a
 * signed link is held by anyone with the URL — and a link that lands in a
 * mail index or a referrer header should not expose a home address. Consumers
 * add PII explicitly, per page, where the surface genuinely warrants it.
 */
final class OrderSummary
{
    /** @return array<string, mixed> */
    public static function for(Order $order, bool $withLines = false): array
    {
        $subtotal = (int) ($order->subtotal ?? 0);
        $tax = (int) ($order->tax ?? 0);
        $total = (int) ($order->total ?? 0);
        $shippingCost = (int) ($order->shipping_cost ?? 0);
        $discount = (int) ($order->discount ?? 0);

        $summary = [
            'id' => $order->id,
            'orderNumber' => $order->order_number,
            'status' => $order->status->value,
            'createdAt' => $order->created_at?->toIso8601String(),
            'currency' => $order->currency->value,
            'subtotal' => $subtotal,
            'formattedSubtotal' => $order->currency->format($subtotal),
            'tax' => $tax,
            'formattedTax' => $order->currency->format($tax),
            'total' => $total,
            'formattedTotal' => $order->currency->format($total),
            // The deduction, and the code that caused it. Shown together or
            // not at all: a total less than subtotal + shipping with nothing
            // explaining the gap reads as an error, and a shopper checking
            // their bank statement has no way to reconcile it. `hasDiscount`
            // rather than `discount > 0` in each template — one rule, one
            // place, every surface agrees.
            'hasDiscount' => $discount > 0,
            'discount' => $discount,
            'formattedDiscount' => $order->currency->format($discount),
            'voucherCode' => $order->voucher_code,
            // An order that never shipped hides the line entirely rather than
            // rendering "Shipping €0.00", which reads as "postage was free
            // this time" instead of "there was no postage". Derived from the
            // snapshot: CreateOrder writes no shipping_method_identifier when
            // it ran without a ShippingSnapshot.
            'requiresShipping' => $order->shipping_method_identifier !== null,
            'shippingCost' => $shippingCost,
            'formattedShippingCost' => $order->currency->format($shippingCost),
            // Whether a VAT line exists at all. The flag, not the amount,
            // decides: 0 tax on a VAT-charging shop is a fact worth showing
            // ("incl. 0% — export"), whereas a shop that charges no VAT
            // (e.g. German §19 Kleinunternehmer) must not show the line —
            // on an invoice it would be actively wrong.
            'showsVat' => (bool) config('commerce.order.summary.shows_vat', true),
        ];

        if ($withLines) {
            $summary['lines'] = $order->lines->map(
                fn (OrderLine $line): array => self::line($line, $order),
            )->all();
        }

        return $summary;
    }

    /** @return array<string, mixed> */
    private static function line(OrderLine $line, Order $order): array
    {
        $unitPrice = (int) ($line->unit_price ?? 0);
        $lineTotal = (int) ($line->line_total ?? 0);

        return [
            'id' => $line->id,
            'description' => $line->description,
            'quantity' => (int) $line->quantity,
            'unitPrice' => $unitPrice,
            'formattedUnitPrice' => $order->currency->format($unitPrice),
            'lineTotal' => $lineTotal,
            'formattedLineTotal' => $order->currency->format($lineTotal),
            'isPreOrder' => (bool) $line->is_pre_order,
            'expectedShipDate' => $line->expected_ship_date?->toDateString(),
        ];
    }
}
