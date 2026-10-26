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
use InOtherShops\Commerce\Exceptions\OrderFrozenException;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Location\Concerns\InteractsWithAddresses;
use InOtherShops\Location\Contracts\HasAddresses;
use InOtherShops\Location\Enums\AddressType;
use InOtherShops\Payment\Concerns\InteractsWithPayments;
use InOtherShops\Payment\Contracts\HasPayments;
use InOtherShops\Payment\Enums\PaymentStatus;
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

    /**
     * What the customer was charged, and under which number: frozen once the
     * order is paid ({@see self::isFrozen()}). Notes, email, locale, customer
     * and status stay editable.
     */
    public const FROZEN_WHEN_PAID = [
        'order_number',
        'currency',
        'subtotal',
        'discount',
        'voucher_code',
        'shipping_method_identifier',
        'shipping_cost',
        'shipping_cost_currency',
        'tax',
        'tax_rate_bps',
        'tax_rate_country_code',
        'tax_summary',
        'total',
    ];

    protected static function booted(): void
    {
        static::updating(function (Order $order): void {
            $changed = array_values(array_intersect(array_keys($order->getDirty()), self::FROZEN_WHEN_PAID));

            if ($changed !== [] && $order->isFrozen()) {
                throw OrderFrozenException::fields($order, $changed);
            }
        });

        static::deleting(function (Order $order): void {
            if ($order->isFrozen()) {
                throw OrderFrozenException::deletion($order);
            }
        });
    }

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

    /**
     * Whether money moved for this order: it has a payment that succeeded,
     * refunded since or not. From then on its money fields, lines and
     * addresses are the record of the sale (bookkeeping brief §12 P-1): the
     * model refuses to change them, and refuses to delete the order.
     */
    public function isFrozen(): bool
    {
        return $this->payments()
            ->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded])
            ->exists();
    }

    /**
     * Whether an admin may record that this order was paid outside a gateway:
     * it is not cancelled, has something to pay, and has no payment pending or
     * settled. A failed card attempt does not stand in the way.
     */
    public function acceptsManualPayment(): bool
    {
        return $this->status !== OrderStatus::Cancelled
            && $this->total > 0
            && $this->payments()
                ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded])
                ->doesntExist();
    }

    public function getPaymentTotalDue(): int
    {
        return (int) $this->total;
    }

    /**
     * Whether this order may be deleted (audit M3 / D4). Only a fresh Pending
     * order carrying no payment row — an abandoned checkout that never reached
     * the gateway — is deletable. Any payment row, or any status past Pending,
     * makes deletion destroy the record of money that moved. The Filament
     * delete/bulk-delete and address-delete actions gate on this; consumers may
     * also enforce it at the policy layer.
     */
    public function isDeletable(): bool
    {
        return $this->status === OrderStatus::Pending
            && $this->payments()->doesntExist();
    }

    /**
     * Whether stock held for this order has been handed back — any of its
     * reservations is Released. On a Pending order that means the expiry cron
     * beat the payment (audit F14), wholly or for some lines: confirming it
     * would ship goods the ledger no longer holds for it. `UpdateOrderStatus`
     * refuses Pending → Confirmed on it and `ConfirmOrder` flags it instead.
     * (A Released reservation on a Confirmed order is ordinary — a partial
     * refund's restock — so this answers a question only the confirm asks.)
     */
    public function hasReleasedStock(): bool
    {
        return Inventory::stockReservation()::query()
            ->where('reference_type', $this->getMorphClass())
            ->where('reference_id', $this->getKey())
            ->where('status', ReservationStatus::Released)
            ->exists();
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
