<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Resources\Read;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use InOtherShops\Agent\Http\Support\ReadFormat;

/**
 * A refund with its order. Refunds have no currency column: theirs is the
 * payment's, or the order's when no payment is linked. Left out on purpose:
 * `actor_id`.
 *
 * @mixin \InOtherShops\Commerce\Order\Models\Refund
 */
final class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'payment_id' => $this->payment_id,
            'gateway' => $this->gateway,
            'gateway_refund_id' => $this->gateway_refund_id,
            'amount' => $this->amount,
            'currency' => ReadFormat::value($this->payment?->currency ?? $this->order->currency),
            'tax_summary' => ReadFormat::summary($this->taxSummary()),
            'reason' => $this->reason,
            'actor_source' => ReadFormat::value($this->actor_source),
            'actor_label' => $this->actor_label,
            'settlement_reference' => $this->settlement_reference,
            'fee' => $this->fee,
            'settled_amount' => $this->settled_amount,
            'settled_currency' => $this->settled_currency,
            'exchange_rate' => $this->exchange_rate,
            'created_at' => ReadFormat::at($this->created_at),
            'updated_at' => ReadFormat::at($this->updated_at),
            'order' => (new OrderResource($this->order))->toArray($request),
        ];
    }
}
