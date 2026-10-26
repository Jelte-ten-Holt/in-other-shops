<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Resources\Read;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use InOtherShops\Agent\Http\Support\ReadFormat;
use InOtherShops\Shipping\Actions\CalculateShipmentValue;
use InOtherShops\Shipping\Models\ShipmentItem;

/**
 * A shipment with what it carries of its order's value (CalculateShipmentValue)
 * and the order itself.
 *
 * @mixin \InOtherShops\Shipping\Models\Shipment
 */
final class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $value = app(CalculateShipmentValue::class)($this->resource);

        return [
            'id' => $this->id,
            'shippable_type' => $this->shippable_type,
            'shippable_id' => $this->shippable_id,
            'status' => ReadFormat::value($this->status),
            'method' => $this->method,
            'carrier' => $this->carrier,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => $this->tracking_url,
            'shipped_at' => ReadFormat::at($this->shipped_at),
            'delivered_at' => ReadFormat::at($this->delivered_at),
            'amount' => $value->amount,
            'currency' => $value->currency->value,
            'tax_summary' => ReadFormat::summary($value->taxSummary),
            'items' => $this->items->sortBy('id')->values()->map(fn (ShipmentItem $item): array => [
                'id' => $item->id,
                'order_line_id' => $item->order_line_id,
                'quantity' => $item->quantity,
            ])->all(),
            'created_at' => ReadFormat::at($this->created_at),
            'updated_at' => ReadFormat::at($this->updated_at),
            'order' => (new OrderResource($this->shippable))->toArray($request),
        ];
    }
}
