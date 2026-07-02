<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Database\Factories\OrderFactory;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Location\Concerns\InteractsWithAddresses;
use InOtherShops\Location\Contracts\HasAddresses;
use InOtherShops\Location\Enums\AddressType;
use InOtherShops\Payment\Concerns\InteractsWithPayments;
use InOtherShops\Payment\Contracts\HasPayments;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;
use InOtherShops\Shipping\Concerns\InteractsWithShipment;
use InOtherShops\Shipping\Contracts\HasShipment;
use InOtherShops\Shipping\Enums\ShipmentStatus;

class Order extends Model implements HasAddresses, HasPayments, HasShipment
{
    use HasFactory;
    use InteractsWithAddresses;
    use InteractsWithPayments;
    use InteractsWithShipment;

    protected $guarded = [];

    protected static string $factory = OrderFactory::class;

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'currency' => Currency::class,
            'subtotal' => 'integer',
            'tax' => 'integer',
            'tax_rate_bps' => 'integer',
            'tax_summary' => 'array',
            'discount' => 'integer',
            'total' => 'integer',
            'shipping_cost' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Commerce::customer());
    }

    /**
     * The per-rate VAT breakdown (invoice / VAT-return shape). Reads from the
     * `tax_summary` column today; callers go through this accessor so the
     * storage can change without touching them.
     *
     * @return list<TaxBreakdownLine>
     */
    public function taxSummary(): array
    {
        return TaxBreakdownLine::listFromRows($this->tax_summary);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(Commerce::orderLine());
    }

    public function shippingAddress(): MorphMany
    {
        return $this->addresses()->whereIn('type', [AddressType::Shipping, AddressType::ShippingAndBilling]);
    }

    public function billingAddress(): MorphMany
    {
        return $this->addresses()->whereIn('type', [AddressType::Billing, AddressType::ShippingAndBilling]);
    }

    public function getPaymentTotalDue(): int
    {
        return (int) $this->total;
    }

    /**
     * The order is complete when it has been confirmed, has at least one
     * Shipment, every Shipment is Delivered, and the order is paid in
     * full. Computed from the three independent state machines (Order,
     * Payment, Shipment) — there is no `completed_at` column.
     */
    public function isComplete(): bool
    {
        if ($this->status !== OrderStatus::Confirmed) {
            return false;
        }

        if (! $this->isPaid()) {
            return false;
        }

        $shipments = $this->shipments;

        if ($shipments->isEmpty()) {
            return false;
        }

        return $shipments->every(
            fn ($shipment) => $shipment->status === ShipmentStatus::Delivered,
        );
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Commerce::refund());
    }

    /**
     * Total gross cents refunded across all refunds on this order. Refund state
     * is derived here rather than stored as an OrderStatus case — order status
     * stays a fulfilment concept.
     */
    public function refundedTotal(): int
    {
        return (int) $this->refunds()->sum('amount');
    }

    public function isRefunded(): bool
    {
        $refunded = $this->refundedTotal();

        return $refunded > 0 && $refunded >= $this->total;
    }

    public function isPartiallyRefunded(): bool
    {
        $refunded = $this->refundedTotal();

        return $refunded > 0 && $refunded < $this->total;
    }
}
