<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Concerns;

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
