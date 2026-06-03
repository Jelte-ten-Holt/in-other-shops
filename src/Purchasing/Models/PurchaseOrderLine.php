<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Models;

use InOtherShops\Purchasing\Database\Factories\PurchaseOrderLineFactory;
use InOtherShops\Purchasing\Purchasing;
use InOtherShops\Tax\Enums\TaxCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of a purchase order. `unit_cost` is the net cost per unit (cents);
 * `input_vat` is the reclaimable VAT transcribed from the supplier invoice
 * (nullable — finalised once the VAT brief lands). `quantity_received` is a
 * cached aggregate kept consistent by ReceiveItems; the StockMovement ledger
 * (reason: Received, referencing this line) is the source of truth for receipts.
 */
class PurchaseOrderLine extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new PurchaseOrderLineFactory;
    }

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'quantity_received' => 'integer',
            'unit_cost' => 'integer',
            'input_vat' => 'integer',
            'line_cost' => 'integer',
            'tax_category' => TaxCategory::class,
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(Purchasing::purchaseOrder());
    }

    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    public function outstandingQuantity(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }

    public function isFullyReceived(): bool
    {
        return $this->quantity_received >= $this->quantity_ordered;
    }
}
