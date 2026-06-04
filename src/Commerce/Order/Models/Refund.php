<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Database\Factories\RefundFactory;
use InOtherShops\Commerce\Order\DTOs\RefundActor;
use InOtherShops\Commerce\Order\Enums\RefundActorSource;
use InOtherShops\Payment\Payment;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;

/**
 * A single refund against an order — the record of money returned. Refund state
 * is queried through the order's `refunds` relationship rather than an
 * OrderStatus case (order status stays a fulfilment concept). Multiple partial
 * refunds are multiple rows; `gateway_refund_id` is unique per gateway so an
 * admin refund and its echoing webhook converge on one row.
 */
class Refund extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new RefundFactory;
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'tax_summary' => 'array',
            'actor_source' => RefundActorSource::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Commerce::order());
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::payment());
    }

    /**
     * This refund's reversed VAT, per rate bracket (the delta it contributes).
     *
     * @return list<TaxBreakdownLine>
     */
    public function taxSummary(): array
    {
        return array_map(
            fn (array $row): TaxBreakdownLine => new TaxBreakdownLine(
                rateBps: (int) $row['rate_bps'],
                taxableBase: (int) $row['taxable_base'],
                tax: (int) $row['tax'],
            ),
            $this->tax_summary ?? [],
        );
    }

    public function actor(): RefundActor
    {
        return new RefundActor(
            source: $this->actor_source,
            id: $this->actor_id,
            label: $this->actor_label,
        );
    }
}
