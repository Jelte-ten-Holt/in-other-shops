<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Contracts;

use InOtherShops\Inventory\Contracts\HasStock;

/**
 * Marks a consumer catalog model as purchasable — something inventory is bought
 * into. Extends {@see HasStock} because receiving a purchase order line moves
 * stock, so a purchasable must always resolve to a StockItem.
 *
 * The Filament purchase-line picker discovers implementers by walking the morph
 * map and filtering for this contract, so a model declares "I can be purchased"
 * simply by implementing it. The two methods supply what the picker and the
 * receive flow need; {@see InteractsWithPurchasing} provides sensible defaults.
 */
interface HasPurchases extends HasStock
{
    /**
     * The column plucked for option labels in the purchase-line product picker.
     */
    public static function purchasableTitleColumn(): string;

    /**
     * Catalog snapshot copied onto a purchase order line on selection. Cost is
     * NOT included — it is transcribed from the supplier invoice, not the
     * catalog.
     *
     * @return array{description: string, sku: string|null}
     */
    public function toPurchaseLineData(): array;
}
