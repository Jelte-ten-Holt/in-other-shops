<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Actions;

use InOtherShops\Media\Media as MediaRegistry;
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
            $mediaIds = $variant->media()->pluck('media.id')->all();

            $variant->delete();

            $variant->prices()->delete();
            $variant->stockItem()->delete();
            $variant->media()->detach();

            $this->deleteUnreferencedMedia($mediaIds);
        });

        VariantDeleted::dispatch($variant);
    }

    /**
     * Detaching only drops the pivot — the `media` rows, and the files they
     * point at, used to survive every variant deletion as permanent orphans.
     * A media row can legitimately be shared with another parent (the same
     * photo on the product and on one of its variants), so delete only the
     * rows nothing points at any more, and let `Media::deleting` decide about
     * the file.
     *
     * @param  list<int>  $mediaIds
     */
    private function deleteUnreferencedMedia(array $mediaIds): void
    {
        if ($mediaIds === []) {
            return;
        }

        $mediaModel = MediaRegistry::media();
        $mediableModel = MediaRegistry::mediable();

        $stillAttached = $mediableModel::query()
            ->whereIn('media_id', $mediaIds)
            ->pluck('media_id')
            ->all();

        $unreferenced = array_diff($mediaIds, $stillAttached);

        if ($unreferenced === []) {
            return;
        }

        $mediaModel::query()
            ->whereIn('id', $unreferenced)
            ->get()
            ->each
            ->delete();
    }
}
