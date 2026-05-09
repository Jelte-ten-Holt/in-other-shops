<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \InOtherShops\Pricing\Models\Price */
final class PriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'amount' => $this->amount,
            'formatted' => $this->formattedAmount(),
            'currency' => $this->currency->value,
            'compare_at_amount' => $this->compare_at_amount,
            'compare_at_formatted' => $this->compare_at_amount !== null
                ? $this->currency->format($this->compare_at_amount)
                : null,
        ];
    }
}
