<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Models;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Purchasing\Database\Factories\PurchaseOrderFactory;
use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Purchasing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An inbound purchase order: what we bought from a supplier, at what (net) cost,
 * and where it is in the order → receive lifecycle. Monetary fields are integer
 * cents, net of reclaimable VAT (input VAT lives per line). `total` is the net
 * landed cost: line subtotal + shipping + customs.
 */
class PurchaseOrder extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static string $factory = PurchaseOrderFactory::class;

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'currency' => Currency::class,
            'ordered_at' => 'datetime',
            'expected_delivery_at' => 'date',
            'shipping_cost' => 'integer',
            'customs_cost' => 'integer',
            'subtotal' => 'integer',
            'total' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Purchasing::supplier());
    }

    public function lines(): HasMany
    {
        return $this->hasMany(Purchasing::purchaseOrderLine());
    }
}
