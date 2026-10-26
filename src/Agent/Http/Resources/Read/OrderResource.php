<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Resources\Read;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use InOtherShops\Agent\Http\Support\ReadFormat;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Location\Models\Address;

/**
 * The order as the read API embeds it in a payment, a refund or a shipment.
 * Left out on purpose: `notes` (admin-internal). Email and phone are sent; the
 * bookkeeping app strips them before it stores anything.
 *
 * @mixin \InOtherShops\Commerce\Order\Models\Order
 */
final class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => ReadFormat::value($this->status),
            'currency' => ReadFormat::value($this->currency),
            'locale' => $this->locale,
            'email' => $this->email,
            'customer' => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'phone' => $this->customer->phone,
            ],
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'voucher_code' => $this->voucher_code,
            'shipping_method_identifier' => $this->shipping_method_identifier,
            'shipping_cost' => $this->shipping_cost,
            'shipping_cost_currency' => $this->shipping_cost_currency,
            'tax' => $this->tax,
            'tax_rate_bps' => $this->tax_rate_bps,
            'tax_rate_country_code' => $this->tax_rate_country_code,
            'tax_summary' => ReadFormat::summary($this->taxSummary()),
            'total' => $this->total,
            'lines' => $this->lines->sortBy('id')->values()->map(fn (OrderLine $line): array => [
                'id' => $line->id,
                'orderable_type' => $line->orderable_type,
                'orderable_id' => $line->orderable_id,
                'description' => $line->description,
                'sku' => $line->sku,
                'currency' => ReadFormat::value($line->currency),
                'unit_price' => $line->unit_price,
                'quantity' => $line->quantity,
                'line_total' => $line->line_total,
                'tax_category' => ReadFormat::value($line->tax_category),
                'tax_rate_bps' => $line->tax_rate_bps,
                'tax_amount' => $line->tax_amount,
                'is_pre_order' => $line->is_pre_order,
                'expected_ship_date' => ReadFormat::day($line->expected_ship_date),
                'created_at' => ReadFormat::at($line->created_at),
                'updated_at' => ReadFormat::at($line->updated_at),
            ])->all(),
            'addresses' => $this->addresses->sortBy('id')->values()->map(fn (Address $address): array => [
                'type' => ReadFormat::value($address->type),
                'first_name' => $address->first_name,
                'last_name' => $address->last_name,
                'line_1' => $address->line_1,
                'line_2' => $address->line_2,
                'city' => $address->city,
                'state' => $address->state,
                'postal_code' => $address->postal_code,
                'country_code' => $address->country_code,
                'phone' => $address->phone,
            ])->all(),
            'created_at' => ReadFormat::at($this->created_at),
            'updated_at' => ReadFormat::at($this->updated_at),
        ];
    }
}
