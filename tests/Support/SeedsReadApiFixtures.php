<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns the responses of a bookkeeping read API fixture (tests/Fixtures/Books)
 * back into the rows they describe, so the contract test can ask the API for
 * them again. Writes through the query builder with every id and timestamp as
 * the fixture gives it: the point is the state, not how a shop reached it, and
 * a paid order is past the model's own freeze.
 *
 * A pull is seeded whole before any of its requests runs, and later pulls
 * overwrite rows by id, as the shop's own writes would have.
 */
trait SeedsReadApiFixtures
{
    private function readApiFixture(string $name): object
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__).'/Fixtures/Books/'.$name.'.json'),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function seedPull(object $pull): void
    {
        foreach ($pull->exchanges as $exchange) {
            foreach ($exchange->response->data as $item) {
                match ($exchange->resource) {
                    'payments' => $this->seedPayment($item),
                    'refunds' => $this->seedRefund($item),
                    'shipments' => $this->seedShipment($item),
                    'purchase_orders' => $this->seedPurchaseOrder($item),
                };
            }
        }
    }

    private function seedPayment(object $payment): void
    {
        $this->seedOrder($payment->order);

        $this->upsertRow('payments', $payment->id, [
            ...$this->columns($payment, [
                'payable_type', 'payable_id', 'amount', 'amount_refunded', 'currency', 'status', 'gateway',
                'gateway_reference', 'charge_reference', 'settlement_reference', 'fee', 'net', 'settled_amount',
                'settled_currency', 'exchange_rate',
            ]),
            'paid_at' => $this->at($payment->paid_at),
            'gateway_data' => null,
            'created_at' => $this->at($payment->created_at),
            'updated_at' => $this->at($payment->updated_at),
        ]);
    }

    private function seedRefund(object $refund): void
    {
        $this->seedOrder($refund->order);

        $this->upsertRow('refunds', $refund->id, [
            ...$this->columns($refund, [
                'order_id', 'payment_id', 'gateway', 'gateway_refund_id', 'amount', 'reason', 'actor_source',
                'actor_label', 'settlement_reference', 'fee', 'settled_amount', 'settled_currency', 'exchange_rate',
            ]),
            'tax_summary' => json_encode($refund->tax_summary),
            'actor_id' => null,
            'created_at' => $this->at($refund->created_at),
            'updated_at' => $this->at($refund->updated_at),
        ]);
    }

    private function seedShipment(object $shipment): void
    {
        $this->seedOrder($shipment->order);

        $this->upsertRow('shipments', $shipment->id, [
            ...$this->columns($shipment, [
                'shippable_type', 'shippable_id', 'status', 'method', 'carrier', 'tracking_number', 'tracking_url',
            ]),
            'shipped_at' => $this->at($shipment->shipped_at),
            'delivered_at' => $this->at($shipment->delivered_at),
            'created_at' => $this->at($shipment->created_at),
            'updated_at' => $this->at($shipment->updated_at),
        ]);

        foreach ($shipment->items as $item) {
            $this->upsertRow('shipment_items', $item->id, [
                'shipment_id' => $shipment->id,
                'order_line_id' => $item->order_line_id,
                'quantity' => $item->quantity,
                'created_at' => $this->at($shipment->created_at),
                'updated_at' => $this->at($shipment->created_at),
            ]);
        }
    }

    private function seedPurchaseOrder(object $order): void
    {
        $supplier = $order->supplier;

        $this->upsertRow('suppliers', $supplier->id, [
            ...$this->columns($supplier, ['name', 'contact_email', 'default_currency', 'payment_terms', 'vat_id', 'country_code']),
            'address_line_1' => $supplier->address?->line_1,
            'address_line_2' => $supplier->address?->line_2,
            'city' => $supplier->address?->city,
            'postal_code' => $supplier->address?->postal_code,
            'notes' => null,
            'created_at' => $this->at($order->created_at),
            'updated_at' => $this->at($order->created_at),
        ]);

        $this->upsertRow('purchase_orders', $order->id, [
            ...$this->columns($order, [
                'reference', 'status', 'currency', 'expected_delivery_at', 'shipping_cost', 'customs_cost', 'subtotal',
                'total', 'supplier_invoice_number', 'supplier_invoice_date', 'supplier_invoice_total', 'amount_paid',
            ]),
            'supplier_id' => $supplier->id,
            'ordered_at' => $this->at($order->ordered_at),
            'supplier_invoice_tax_summary' => $order->supplier_invoice_tax_summary === null ? null : json_encode($order->supplier_invoice_tax_summary),
            'paid_at' => $this->at($order->paid_at),
            'notes' => null,
            'created_at' => $this->at($order->created_at),
            'updated_at' => $this->at($order->updated_at),
        ]);

        foreach ($order->lines as $line) {
            $this->upsertRow('purchase_order_lines', $line->id, [
                ...$this->columns($line, [
                    'purchasable_type', 'purchasable_id', 'description', 'sku', 'quantity_ordered', 'quantity_received',
                    'unit_cost', 'input_vat', 'tax_category', 'line_cost',
                ]),
                'purchase_order_id' => $order->id,
                'created_at' => $this->at($order->created_at),
                'updated_at' => $this->at($order->created_at),
            ]);
        }
    }

    private function seedOrder(object $order): void
    {
        if ($order->customer !== null) {
            $this->upsertRow('customers', $order->customer->id, [
                ...$this->columns($order->customer, ['name', 'email', 'phone']),
                'created_at' => $this->at($order->created_at),
                'updated_at' => $this->at($order->created_at),
            ]);
        }

        $this->upsertRow('orders', $order->id, [
            ...$this->columns($order, [
                'order_number', 'status', 'currency', 'locale', 'email', 'subtotal', 'discount', 'voucher_code',
                'shipping_method_identifier', 'shipping_cost', 'shipping_cost_currency', 'tax', 'tax_rate_bps',
                'tax_rate_country_code', 'total',
            ]),
            'customer_id' => $order->customer?->id,
            'tax_summary' => json_encode($order->tax_summary),
            'notes' => null,
            'created_at' => $this->at($order->created_at),
            'updated_at' => $this->at($order->updated_at),
        ]);

        foreach ($order->lines as $line) {
            $this->upsertRow('order_lines', $line->id, [
                ...$this->columns($line, [
                    'orderable_type', 'orderable_id', 'description', 'sku', 'currency', 'unit_price', 'quantity',
                    'line_total', 'tax_category', 'tax_rate_bps', 'tax_amount', 'is_pre_order', 'expected_ship_date',
                ]),
                'order_id' => $order->id,
                'created_at' => $this->at($line->created_at),
                'updated_at' => $this->at($line->updated_at),
            ]);
        }

        // Addresses carry no id in the API; their order is the insert order.
        DB::table('addresses')->where('addressable_type', 'order')->where('addressable_id', $order->id)->delete();

        foreach ($order->addresses as $address) {
            DB::table('addresses')->insert([
                ...$this->columns($address, [
                    'type', 'first_name', 'last_name', 'line_1', 'line_2', 'city', 'state', 'postal_code', 'country_code', 'phone',
                ]),
                'addressable_type' => 'order',
                'addressable_id' => $order->id,
                'created_at' => $this->at($order->created_at),
                'updated_at' => $this->at($order->created_at),
            ]);
        }
    }

    /** @param  array<string, mixed>  $values */
    private function upsertRow(string $table, int $id, array $values): void
    {
        DB::table($table)->updateOrInsert(['id' => $id], $values);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function columns(object $item, array $keys): array
    {
        $columns = [];

        foreach ($keys as $key) {
            $columns[$key] = $item->{$key};
        }

        return $columns;
    }

    private function at(?string $iso): ?string
    {
        return $iso === null
            ? null
            : CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $iso, 'UTC')->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
