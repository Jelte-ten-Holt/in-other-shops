<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Actions;

use InOtherShops\Variants\Events\VariantDeleted;
use InOtherShops\Variants\Models\Variant;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a variant and its owned polymorphic rows (prices, stock, media links).
 * The cart-deletion guard on the model's delete fires first — if a live cart
 * references the variant, deletion is blocked and the whole transaction rolls
 * back. Option-value pivot rows cascade via FK.
 */
final class DeleteVariant
{
    public function __invoke(Variant $variant): void
    {
        DB::transaction(function () use ($variant): void {
            $variant->delete();

            $variant->prices()->delete();
            $variant->stockItem()->delete();
            $variant->media()->detach();
        });

        VariantDeleted::dispatch($variant);
    }
}
