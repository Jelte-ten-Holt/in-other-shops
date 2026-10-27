<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Resources\Read;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use InOtherShops\Agent\Http\Support\ReadFormat;

/**
 * A payment with its order. Left out on purpose: `gateway_data` (raw provider
 * metadata, unstable).
 *
 * @mixin \InOtherShops\Payment\Models\Payment
 */
final class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payable_type' => $this->payable_type,
            'payable_id' => $this->payable_id,
            'amount' => $this->amount,
            'amount_refunded' => $this->amount_refunded,
            'currency' => ReadFormat::value($this->currency),
            'status' => ReadFormat::value($this->status),
            'gateway' => $this->gateway,
            'gateway_reference' => $this->gateway_reference,
            'charge_reference' => $this->charge_reference,
            'settlement_reference' => $this->settlement_reference,
            'paid_at' => ReadFormat::at($this->paid_at),
            'fee' => $this->fee,
            'net' => $this->net,
            'settled_amount' => $this->settled_amount,
            'settled_currency' => $this->settled_currency,
            'exchange_rate' => $this->exchange_rate,
            'created_at' => ReadFormat::at($this->created_at),
            'updated_at' => ReadFormat::at($this->updated_at),
            'order' => (new OrderResource($this->payable))->toArray($request),
        ];
    }
}
