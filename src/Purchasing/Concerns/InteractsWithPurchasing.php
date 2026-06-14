<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Concerns;

use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Purchasing;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Default behaviour for a {@see \InOtherShops\Purchasing\Contracts\HasPurchases}
 * model: the reverse purchase-line relation plus catalog-snapshot defaults that
 * read `name`/`sku`. Consumers override the snapshot methods when their columns
 * differ.
 */
trait InteractsWithPurchasing
{
    public function purchaseLines(): MorphMany
    {
        return $this->morphMany(Purchasing::purchaseOrderLine(), 'purchasable');
    }

    /**
     * Lines on purchase orders that goods may still arrive against — i.e. orders
     * in a {@see PurchaseOrderStatus::isReceivable()} state (Ordered or
     * Partially received). Draft orders are not yet placed, so their lines are
     * not "incoming"; Received/Cancelled orders have nothing left to arrive.
     * Eager-load `purchaseOrder` for a per-order breakdown.
     */
    public function incomingPurchaseLines(): MorphMany
    {
        return $this->purchaseLines()
            ->whereHas('purchaseOrder', function ($query): void {
                $query->whereIn('status', [
                    PurchaseOrderStatus::Ordered,
                    PurchaseOrderStatus::PartiallyReceived,
                ]);
            });
    }

    /**
     * Total units still expected to arrive across all open purchase orders —
     * the sum of each open line's outstanding (ordered − received) quantity.
     */
    public function incomingQuantity(): int
    {
        return (int) $this->incomingPurchaseLines()
            ->get()
            ->sum(fn ($line): int => $line->outstandingQuantity());
    }

    public static function purchasableTitleColumn(): string
    {
        return 'name';
    }

    /**
     * @return array{description: string, sku: string|null}
     */
    public function toPurchaseLineData(): array
    {
        $titleColumn = static::purchasableTitleColumn();

        return [
            'description' => (string) ($this->{$titleColumn} ?? ''),
            'sku' => $this->sku ?? null,
        ];
    }
}
